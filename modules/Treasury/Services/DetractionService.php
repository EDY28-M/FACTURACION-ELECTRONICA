<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Document;
use App\Models\Tenant\Purchase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\DetractionRecord;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\BusinessDayCalculator;
use Modules\Treasury\Support\DatabaseErrors;
use Modules\Treasury\Support\Money;

/**
 * SPOT (detracciones) control for Treasury Phase 4.
 *
 * Record creation strategy (deliberate): records are created LAZILY and IDEMPOTENTLY by `syncFromSources()`
 * (called unthrottled from the Detracciones screen / manual sync, and through `syncThrottled()` from the
 * Resumen alerts, at most once per throttle window per tenant) instead of hooking Document/Purchase model events. The repository does have `Document::created` hooks (LockedEmissionProvider,
 * DocumentPaymentProvider), but they run inside the emission pipeline and a failure there would break
 * invoicing; a lazy sync keeps issuance flows untouched and is safe to re-run (unique source+side index).
 *
 * Never calls auth(); callers pass an explicit $userId and a $scope array
 * ['is_admin' => bool, 'user_id' => ?int, 'establishment_id' => ?int] for non-admin record ownership.
 */
class DetractionService
{
    public const SOURCE_DOCUMENT = Document::class;
    public const SOURCE_PURCHASE = Purchase::class;

    /**
     * Sources whose detraction JSON cannot be parsed (or whose date is invalid) get a record with this status so the
     * sync window advances instead of re-reading them forever. Never listed, never alerted.
     */
    public const STATUS_SKIPPED = DetractionRecord::STATUS_SKIPPED;

    private $movementService;
    private $calendar;
    private $taxRateRepository;

    /** @var array<string, bool> */
    private $schemaCache = [];

    /** @var array<string, int> */
    private $depositDayCache = [];

    public function __construct(
        MovementService $movementService,
        BusinessDayCalculator $calendar,
        TaxRateRepository $taxRateRepository
    ) {
        $this->movementService = $movementService;
        $this->calendar = $calendar;
        $this->taxRateRepository = $taxRateRepository;
    }

    // ------------------------------------------------------------------------------------------------
    // Due date
    // ------------------------------------------------------------------------------------------------

    /**
     * Payable deadline: the EARLIER of the first (partial or full) payment date and the 5th business day of the
     * month after the purchase is recorded in the Registro de Compras
     * (docs/plans/tesoreria-investigacion-sunat.md section 2, "When").
     */
    public function computePayableDueDate(string $registryDate, ?string $firstPaymentDate = null): string
    {
        $deadline = $this->calendar->nthBusinessDayOfNextMonth($registryDate, $this->depositBusinessDay($registryDate));

        if ($firstPaymentDate !== null && $firstPaymentDate !== '' && $firstPaymentDate < $deadline) {
            return $firstPaymentDate;
        }

        return $deadline;
    }

    /**
     * Receivable reference date: the latest date by which the buyer should have deposited, ASSUMING it recorded
     * the invoice in the month of issue. It is only an estimate (the buyer's Registro de Compras date is unknown),
     * so the UI labels it "fecha estimada".
     */
    public function computeReceivableExpectedDate(string $dateOfIssue): string
    {
        return $this->calendar->nthBusinessDayOfNextMonth($dateOfIssue, $this->depositBusinessDay($dateOfIssue));
    }

    /**
     * Deposit business day effective on a date: versioned in treasury_tax_rates, with a config last resort.
     */
    private function depositBusinessDay(string $date): int
    {
        $key = substr($date, 0, 7);
        if (!isset($this->depositDayCache[$key])) {
            $day = $this->taxRateRepository->getDetractionDepositBusinessDay($date);
            if ($day === null) {
                $day = max(1, (int) config('treasury.detraction_deposit_business_day_fallback'));
            }
            $this->depositDayCache[$key] = $day;
        }

        return $this->depositDayCache[$key];
    }

    /**
     * True when a due date may still shift because it is not backed by complete data: receivables always (the
     * buyer's Registro de Compras date is unknown) and payables whose due-date year has no holidays configured.
     */
    public function isDueDateEstimate(string $side, ?string $dueDate): bool
    {
        if ($side === DetractionRecord::SIDE_RECEIVABLE) {
            return true;
        }
        if ($dueDate === null) {
            return false;
        }

        return !$this->calendar->hasHolidaysForYear((int) substr($dueDate, 0, 4));
    }

    private function estimateGraceDays(): int
    {
        return max(0, (int) config('treasury.estimated_due_date_grace_days', 0));
    }

    // ------------------------------------------------------------------------------------------------
    // Sync (lazy, idempotent record creation)
    // ------------------------------------------------------------------------------------------------

    /**
     * Creates the missing records for sales documents (receivable) and purchases (payable) that carry a detraction,
     * and refreshes the due date of pending payable records (first payment may have appeared).
     *
     * `$limit` bounds the records handled PER SIDE (created or marked skipped) and, as a safety net, the rows read.
     * Unparseable sources are marked with a `skipped` record so they are never read again.
     *
     * @return array{created: int, updated: int, skipped: int, scanned: int}
     */
    public function syncFromSources(int $limit = 500): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'scanned' => 0];

        if (!$this->hasTable('detraction_records')) {
            return $result;
        }

        foreach ([$this->syncReceivables($limit), $this->syncPayables($limit)] as $side) {
            $result['created'] += $side['created'];
            $result['skipped'] += $side['skipped'];
            $result['scanned'] += $side['scanned'];
        }
        $result['updated'] += $this->refreshPayableDueDates($limit);

        return $result;
    }

    /**
     * Runs `syncFromSources()` at most once per throttle window per tenant (cache key includes the tenant database
     * name). Meant for read-heavy callers such as the Resumen alerts.
     *
     * @return array{created: int, updated: int, skipped: int, scanned: int}|null null when the window is still open
     */
    public function syncThrottled(int $limit = 100, ?int $minutes = null): ?array
    {
        $minutes = $minutes ?? max(1, (int) config('treasury.detraction_sync_throttle_minutes', 10));
        $key = 'treasury:detraction_sync:' . md5((string) DB::connection('tenant')->getDatabaseName());

        if (!Cache::add($key, 1, now()->addMinutes($minutes))) {
            return null;
        }

        try {
            return $this->syncFromSources($limit);
        } catch (\Throwable $e) {
            // Do not hold the window closed after a failure.
            Cache::forget($key);

            throw $e;
        }
    }

    /**
     * @return array{created: int, skipped: int, scanned: int}
     */
    private function syncReceivables(int $limit): array
    {
        $counts = ['created' => 0, 'skipped' => 0, 'scanned' => 0];
        if (!$this->hasTable('documents') || !$this->hasColumn('documents', 'detraction')) {
            return $counts;
        }

        $lastId = 0;

        while (($counts['created'] + $counts['skipped']) < $limit && $counts['scanned'] < $limit * 10) {
            $rows = DB::connection('tenant')
                ->table('documents')
                ->leftJoin('detraction_records as dr', function ($join) {
                    $join->on('dr.source_id', '=', 'documents.id')
                        ->where('dr.source_type', '=', self::SOURCE_DOCUMENT)
                        ->where('dr.side', '=', DetractionRecord::SIDE_RECEIVABLE);
                })
                ->whereNull('dr.id')
                ->whereNotNull('documents.detraction')
                ->whereIn('documents.state_type_id', ReceivablePayableService::ACCEPTED_STATE_TYPES)
                ->where('documents.id', '>', $lastId)
                ->orderBy('documents.id')
                ->limit(max(1, min(200, $limit - $counts['created'] - $counts['skipped'])))
                ->get([
                    'documents.id',
                    'documents.user_id',
                    'documents.establishment_id',
                    'documents.date_of_issue',
                    'documents.total',
                    'documents.currency_type_id',
                    'documents.detraction',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $counts['scanned']++;

                $info = $this->parseDetraction($row->detraction);
                $dateOfIssue = $this->normalizeDate($row->date_of_issue);
                if ($info === null || $dateOfIssue === null) {
                    $this->markSkipped(DetractionRecord::SIDE_RECEIVABLE, self::SOURCE_DOCUMENT, $row);
                    $counts['skipped']++;
                    continue;
                }

                $record = $this->createRecordIfMissing([
                    'side' => DetractionRecord::SIDE_RECEIVABLE,
                    'source_type' => self::SOURCE_DOCUMENT,
                    'source_id' => (int) $row->id,
                    'establishment_id' => $row->establishment_id ? (int) $row->establishment_id : null,
                    'user_id' => $row->user_id ? (int) $row->user_id : null,
                    'code' => $info['code'],
                    'rate' => $info['rate'],
                    'currency_type_id' => strtoupper(trim((string) ($row->currency_type_id ?: 'PEN'))),
                    'base_amount' => Money::round((float) $row->total),
                    'amount_pen' => $info['amount_pen'],
                    'bn_account_number' => $info['bank_account'],
                    'registry_date' => null,
                    'due_date' => $this->computeReceivableExpectedDate($dateOfIssue),
                    'status' => DetractionRecord::STATUS_PENDING,
                ]);
                if ($record && $record->wasRecentlyCreated) {
                    $counts['created']++;
                }
            }
        }

        return $counts;
    }

    /**
     * @return array{created: int, skipped: int, scanned: int}
     */
    private function syncPayables(int $limit): array
    {
        $counts = ['created' => 0, 'skipped' => 0, 'scanned' => 0];
        if (!$this->hasTable('purchases') || !$this->hasColumn('purchases', 'detraction')) {
            return $counts;
        }

        $lastId = 0;

        while (($counts['created'] + $counts['skipped']) < $limit && $counts['scanned'] < $limit * 10) {
            $rows = DB::connection('tenant')
                ->table('purchases')
                ->leftJoin('detraction_records as dr', function ($join) {
                    $join->on('dr.source_id', '=', 'purchases.id')
                        ->where('dr.source_type', '=', self::SOURCE_PURCHASE)
                        ->where('dr.side', '=', DetractionRecord::SIDE_PAYABLE);
                })
                ->whereNull('dr.id')
                ->whereNotNull('purchases.detraction')
                ->whereIn('purchases.state_type_id', ReceivablePayableService::ACCEPTED_STATE_TYPES)
                ->where('purchases.id', '>', $lastId)
                ->orderBy('purchases.id')
                ->limit(max(1, min(200, $limit - $counts['created'] - $counts['skipped'])))
                ->get([
                    'purchases.id',
                    'purchases.user_id',
                    'purchases.establishment_id',
                    'purchases.date_of_issue',
                    'purchases.total',
                    'purchases.currency_type_id',
                    'purchases.detraction',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            $firstPayments = $this->loadFirstPaymentDates($rows->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all());

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $counts['scanned']++;

                $info = $this->parseDetraction($row->detraction);
                // The system has no separate "fecha de anotación" for purchases; the issue date is the
                // best available proxy for the Registro de Compras entry month.
                $registryDate = $this->normalizeDate($row->date_of_issue);
                if ($info === null || $registryDate === null) {
                    $this->markSkipped(DetractionRecord::SIDE_PAYABLE, self::SOURCE_PURCHASE, $row);
                    $counts['skipped']++;
                    continue;
                }

                $record = $this->createRecordIfMissing([
                    'side' => DetractionRecord::SIDE_PAYABLE,
                    'source_type' => self::SOURCE_PURCHASE,
                    'source_id' => (int) $row->id,
                    'establishment_id' => $row->establishment_id ? (int) $row->establishment_id : null,
                    'user_id' => $row->user_id ? (int) $row->user_id : null,
                    'code' => $info['code'],
                    'rate' => $info['rate'],
                    'currency_type_id' => strtoupper(trim((string) ($row->currency_type_id ?: 'PEN'))),
                    'base_amount' => Money::round((float) $row->total),
                    'amount_pen' => $info['amount_pen'],
                    'bn_account_number' => $info['bank_account'],
                    'registry_date' => $registryDate,
                    'due_date' => $this->computePayableDueDate($registryDate, $firstPayments[(int) $row->id] ?? null),
                    'status' => DetractionRecord::STATUS_PENDING,
                ]);
                if ($record && $record->wasRecentlyCreated) {
                    $counts['created']++;
                }
            }
        }

        return $counts;
    }

    private function refreshPayableDueDates(int $limit): int
    {
        $records = DetractionRecord::query()
            ->where('side', DetractionRecord::SIDE_PAYABLE)
            ->where('status', DetractionRecord::STATUS_PENDING)
            ->whereNotNull('registry_date')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($records->isEmpty()) {
            return 0;
        }

        $firstPayments = $this->loadFirstPaymentDates($records->pluck('source_id')->map(function ($id) {
            return (int) $id;
        })->all());

        $updated = 0;
        foreach ($records as $record) {
            $due = $this->computePayableDueDate(
                $record->registry_date->format('Y-m-d'),
                $firstPayments[(int) $record->source_id] ?? null
            );
            $current = $record->due_date ? $record->due_date->format('Y-m-d') : null;
            if ($current !== $due) {
                $record->due_date = $due;
                $record->save();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Idempotent insert guarded by the (source_type, source_id, side) unique index.
     */
    private function createRecordIfMissing(array $attributes): ?DetractionRecord
    {
        $existing = DetractionRecord::query()
            ->where('source_type', $attributes['source_type'])
            ->where('source_id', $attributes['source_id'])
            ->where('side', $attributes['side'])
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DetractionRecord::create($attributes);
        } catch (QueryException $e) {
            // Only a duplicate key means "a concurrent sync created it first"; anything else is a real failure.
            if (!DatabaseErrors::isDuplicateKey($e)) {
                throw $e;
            }

            // The loser of the race returns the winner's row (wasRecentlyCreated = false, so it is not counted).
            return DetractionRecord::query()
                ->where('source_type', $attributes['source_type'])
                ->where('source_id', $attributes['source_id'])
                ->where('side', $attributes['side'])
                ->first();
        }
    }

    /**
     * Persists a `skipped` marker for a source whose detraction data cannot be used, so the next sync window
     * does not read it again. Excluded from listings, totals and alerts.
     */
    private function markSkipped(string $side, string $sourceType, object $row): void
    {
        $this->createRecordIfMissing([
            'side' => $side,
            'source_type' => $sourceType,
            'source_id' => (int) $row->id,
            'establishment_id' => $row->establishment_id ? (int) $row->establishment_id : null,
            'user_id' => $row->user_id ? (int) $row->user_id : null,
            'currency_type_id' => strtoupper(trim((string) ($row->currency_type_id ?: 'PEN'))),
            'base_amount' => 0,
            'amount_pen' => 0,
            'status' => self::STATUS_SKIPPED,
        ]);
    }

    /**
     * @return array{code: ?string, rate: ?float, amount_pen: float, bank_account: ?string}|null
     */
    public function parseDetraction($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : (is_object($raw) ? json_decode(json_encode($raw), true) : $raw);
        if (!is_array($decoded) || !isset($decoded['amount']) || !is_numeric($decoded['amount'])) {
            return null;
        }

        $amount = Money::round((float) $decoded['amount']);
        if ($amount < Money::MIN_AMOUNT) {
            return null;
        }

        $code = isset($decoded['detraction_type_id']) ? trim((string) $decoded['detraction_type_id']) : null;
        $percentage = isset($decoded['percentage']) && is_numeric($decoded['percentage'])
            ? round(((float) $decoded['percentage']) / 100, 5)
            : null;
        $bankAccount = isset($decoded['bank_account']) && trim((string) $decoded['bank_account']) !== ''
            ? trim((string) $decoded['bank_account'])
            : null;

        return [
            'code' => $code !== '' ? $code : null,
            'rate' => $percentage,
            'amount_pen' => $amount,
            'bank_account' => $bankAccount,
        ];
    }

    // ------------------------------------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------------------------------------

    /**
     * @param array{status?: string, establishment_id?: ?int, date_start?: ?string, date_end?: ?string, search?: ?string,
     *     is_admin?: bool, user_id?: ?int, page?: int, per_page?: int} $filters
     * @return array{rows: array, totals: array, meta: array}
     */
    public function listRecords(string $side, array $filters = [], ?string $asOfDate = null): array
    {
        $asOf = $asOfDate ?: date('Y-m-d');
        $empty = [
            'rows' => [],
            'totals' => $this->emptyTotals(),
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0],
        ];

        if (!in_array($side, [DetractionRecord::SIDE_RECEIVABLE, DetractionRecord::SIDE_PAYABLE], true)
            || !$this->hasTable('detraction_records')) {
            return $empty;
        }

        $isAdmin = array_key_exists('is_admin', $filters) ? (bool) $filters['is_admin'] : true;
        $userId = isset($filters['user_id']) && $filters['user_id'] !== '' ? (int) $filters['user_id'] : null;
        if (!$isAdmin && $userId === null) {
            return $empty;
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $status = !empty($filters['status']) ? strtolower(trim((string) $filters['status'])) : 'all';

        $base = $this->buildListQuery($side, $filters, $isAdmin, $userId, $asOf, $status);

        $totalsQuery = clone $base;
        $aggregates = $totalsQuery->get(['dr.status', 'dr.due_date', 'dr.amount_pen']);
        $totals = $this->emptyTotals();
        foreach ($aggregates as $agg) {
            $aggDue = $this->normalizeDate($agg->due_date);
            $effective = $this->effectiveStatus((string) $agg->status, $aggDue, $asOf, $this->isDueDateEstimate($side, $aggDue));
            $totals['count']++;
            $totals['amount_pen'] = Money::round($totals['amount_pen'] + (float) $agg->amount_pen);
            $totals['by_status'][$effective]['count']++;
            $totals['by_status'][$effective]['amount_pen'] = Money::round(
                $totals['by_status'][$effective]['amount_pen'] + (float) $agg->amount_pen
            );
        }

        $total = (int) $totals['count'];
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $selectedColumns = $side === DetractionRecord::SIDE_RECEIVABLE
            ? $this->receivableColumns()
            : $this->payableColumns();

        $records = $base
            ->orderByRaw('CASE WHEN dr.due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('dr.due_date', 'asc')
            ->orderBy('dr.id', 'asc')
            ->forPage($page, $perPage)
            ->get($selectedColumns);

        $descriptions = $this->loadDetractionDescriptions();

        $rows = [];
        foreach ($records as $row) {
            $rows[] = $this->transformRow($side, $row, $asOf, $descriptions);
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    private function buildListQuery(string $side, array $filters, bool $isAdmin, ?int $userId, string $asOf, string $status)
    {
        $query = DB::connection('tenant')->table('detraction_records as dr');

        if ($side === DetractionRecord::SIDE_RECEIVABLE) {
            $query->join('documents as s', function ($join) {
                $join->on('s.id', '=', 'dr.source_id')->where('dr.source_type', '=', self::SOURCE_DOCUMENT);
            })->leftJoin('persons as p', 'p.id', '=', 's.customer_id');
        } else {
            $query->join('purchases as s', function ($join) {
                $join->on('s.id', '=', 'dr.source_id')->where('dr.source_type', '=', self::SOURCE_PURCHASE);
            })->leftJoin('persons as p', 'p.id', '=', 's.supplier_id');
        }

        // Records whose source was cancelled/rejected must not appear (nor count in alerts).
        $query->where('dr.side', $side)
            ->where('dr.status', '<>', self::STATUS_SKIPPED)
            ->whereIn('s.state_type_id', ReceivablePayableService::ACCEPTED_STATE_TYPES);

        if (!$isAdmin) {
            $query->where('dr.user_id', $userId);
            $establishmentId = isset($filters['establishment_id']) ? (int) $filters['establishment_id'] : 0;
            $query->where('dr.establishment_id', $establishmentId);
        } else {
            if ($userId !== null) {
                $query->where('dr.user_id', $userId);
            }
            if (isset($filters['establishment_id']) && $filters['establishment_id'] !== null && $filters['establishment_id'] !== '') {
                $query->where('dr.establishment_id', (int) $filters['establishment_id']);
            }
        }

        if (!empty($filters['date_start'])) {
            $query->where('s.date_of_issue', '>=', (string) $filters['date_start']);
        }
        if (!empty($filters['date_end'])) {
            $query->where('s.date_of_issue', '<=', (string) $filters['date_end']);
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('s.number', 'like', $like)
                    ->orWhere('s.series', 'like', $like)
                    ->orWhere('p.name', 'like', $like)
                    ->orWhere('p.number', 'like', $like)
                    ->orWhere('dr.constancia_number', 'like', $like);
            });
        }

        switch ($status) {
            case DetractionRecord::STATUS_OVERDUE:
                $query->where('dr.status', DetractionRecord::STATUS_PENDING)
                    ->whereNotNull('dr.due_date')
                    ->where(function ($q) use ($side, $asOf) {
                        $this->applyOverdueCondition($q, $side, $asOf);
                    });
                break;
            case DetractionRecord::STATUS_PENDING:
                $query->where('dr.status', DetractionRecord::STATUS_PENDING)
                    ->where(function ($q) use ($side, $asOf) {
                        $q->whereNull('dr.due_date')->orWhere(function ($notOverdue) use ($side, $asOf) {
                            $this->applyNotOverdueCondition($notOverdue, $side, $asOf);
                        });
                    });
                break;
            case DetractionRecord::STATUS_DEPOSITED:
            case DetractionRecord::STATUS_RECEIVED:
                $query->where('dr.status', $status);
                break;
        }

        return $query;
    }

    /**
     * Overdue (non-null due date): exact due dates are overdue once strictly past; ESTIMATED ones (receivables, or
     * payables in a year without configured holidays) need the extra grace days. Pure string date comparisons so it
     * runs on any SQL driver.
     */
    private function applyOverdueCondition($query, string $side, string $asOf): void
    {
        $graceCutoff = $this->graceCutoff($asOf);
        $years = $side === DetractionRecord::SIDE_PAYABLE ? $this->configuredHolidayYears() : [];

        $query->where('dr.due_date', '<', $graceCutoff);

        if (!empty($years)) {
            $query->orWhere(function ($q) use ($years, $asOf) {
                $q->where('dr.due_date', '<', $asOf)->where(function ($inYear) use ($years) {
                    foreach ($years as $year) {
                        $inYear->orWhereBetween('dr.due_date', [$year . '-01-01', $year . '-12-31']);
                    }
                });
            });
        }
    }

    /** Exact complement of applyOverdueCondition() for a non-null due date. */
    private function applyNotOverdueCondition($query, string $side, string $asOf): void
    {
        $graceCutoff = $this->graceCutoff($asOf);
        $years = $side === DetractionRecord::SIDE_PAYABLE ? $this->configuredHolidayYears() : [];

        $query->where('dr.due_date', '>=', $graceCutoff);

        if (!empty($years)) {
            $query->where(function ($q) use ($years, $asOf) {
                $q->where('dr.due_date', '>=', $asOf)->orWhere(function ($outside) use ($years) {
                    foreach ($years as $year) {
                        $outside->where(function ($notInYear) use ($year) {
                            $notInYear->where('dr.due_date', '<', $year . '-01-01')
                                ->orWhere('dr.due_date', '>', $year . '-12-31');
                        });
                    }
                });
            });
        }
    }

    private function graceCutoff(string $asOf): string
    {
        $grace = $this->estimateGraceDays();

        return $grace > 0 ? date('Y-m-d', strtotime($asOf . ' -' . $grace . ' days')) : $asOf;
    }

    /**
     * @return array<int, int>
     */
    private function configuredHolidayYears(): array
    {
        return $this->calendar->holidayYears();
    }

    private function receivableColumns(): array
    {
        return $this->commonColumns([
            's.customer_id as party_id',
            'p.name as party_name',
            'p.number as party_number',
        ]);
    }

    private function payableColumns(): array
    {
        return $this->commonColumns([
            's.supplier_id as party_id',
            'p.name as party_name',
            'p.number as party_number',
        ]);
    }

    private function commonColumns(array $extra): array
    {
        return array_merge([
            'dr.id',
            'dr.side',
            'dr.source_type',
            'dr.source_id',
            'dr.establishment_id',
            'dr.user_id',
            'dr.code',
            'dr.rate',
            'dr.currency_type_id',
            'dr.base_amount',
            'dr.amount_pen',
            'dr.bn_account_number',
            'dr.registry_date',
            'dr.due_date',
            'dr.deposited_at',
            'dr.constancia_number',
            'dr.operation_number',
            'dr.deposit_movement_id',
            'dr.status',
            's.series',
            's.number',
            's.date_of_issue',
            's.document_type_id',
        ], $extra);
    }

    private function transformRow(string $side, $row, string $asOf, array $descriptions): array
    {
        $dueDate = $this->normalizeDate($row->due_date);
        $isEstimate = $this->isDueDateEstimate($side, $dueDate);
        $effective = $this->effectiveStatus((string) $row->status, $dueDate, $asOf, $isEstimate);
        $daysOverdue = 0;
        if ($effective === DetractionRecord::STATUS_OVERDUE && $dueDate !== null) {
            $daysOverdue = (int) Carbon::parse($dueDate)->diffInDays(Carbon::parse($asOf));
        }

        $dateOfIssue = $this->normalizeDate($row->date_of_issue);
        $expected = ($row->code !== null && $dateOfIssue !== null)
            ? $this->taxRateRepository->getDetractionRule((string) $row->code, $dateOfIssue)
            : null;
        $rate = $row->rate !== null ? (float) $row->rate : null;

        return [
            'id' => (int) $row->id,
            'side' => $side,
            'source_type' => $row->source_type === self::SOURCE_DOCUMENT ? 'document' : 'purchase',
            'source_id' => (int) $row->source_id,
            'establishment_id' => $row->establishment_id ? (int) $row->establishment_id : null,
            'number_full' => trim((string) $row->series) . '-' . trim((string) $row->number),
            'document_type_id' => (string) ($row->document_type_id ?? ''),
            'date_of_issue' => $dateOfIssue,
            'party_name' => $row->party_name ?: ($side === DetractionRecord::SIDE_RECEIVABLE ? 'Sin cliente' : 'Sin proveedor'),
            'party_number' => $row->party_number,
            'currency_type_id' => (string) $row->currency_type_id,
            'base_amount' => Money::round((float) $row->base_amount),
            'code' => $row->code,
            'code_description' => $row->code !== null ? ($descriptions[(string) $row->code] ?? null) : null,
            'rate' => $rate,
            'rate_percent' => $rate !== null ? round($rate * 100, 2) : null,
            'expected_rate_percent' => $expected ? round($expected['rate'] * 100, 2) : null,
            'rate_differs' => $expected !== null && $rate !== null && abs($expected['rate'] - $rate) > 0.00001,
            'amount_pen' => Money::round((float) $row->amount_pen),
            // Receivable: what the customer must still pay to the BN account; receivable net = total - detraction.
            'net_amount' => $row->currency_type_id === 'PEN'
                ? Money::round(max(0.0, (float) $row->base_amount - (float) $row->amount_pen))
                : null,
            'bn_account_number' => $row->bn_account_number,
            'registry_date' => $this->normalizeDate($row->registry_date),
            'due_date' => $dueDate,
            'due_date_is_estimate' => $isEstimate,
            'days_overdue' => $daysOverdue,
            'status' => $effective,
            'stored_status' => (string) $row->status,
            'deposited_at' => $this->normalizeDate($row->deposited_at),
            'constancia_number' => $row->constancia_number,
            'operation_number' => $row->operation_number,
            'deposit_movement_id' => $row->deposit_movement_id ? (int) $row->deposit_movement_id : null,
        ];
    }

    // ------------------------------------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------------------------------------

    /**
     * receivable -> "received": the customer deposited into my BN account (inflow into the detraction account).
     * payable -> "deposited": I deposited into my supplier's BN account (outflow from the chosen own account).
     *
     * @param array{date: string, bank_account_id?: ?int, constancia_number?: ?string, operation_number?: ?string} $payload
     * @param array{is_admin?: bool, user_id?: ?int, establishment_id?: ?int} $scope
     */
    public function registerDeposit(int $recordId, array $payload, int $userId, array $scope = []): DetractionRecord
    {
        $date = trim((string) ($payload['date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages([
                'date' => ['La fecha del depósito debe tener el formato YYYY-MM-DD.'],
            ]);
        }

        $constancia = $this->nullableTrim($payload['constancia_number'] ?? null);
        if ($constancia !== null && mb_strlen($constancia) > 30) {
            throw ValidationException::withMessages([
                'constancia_number' => ['El número de constancia no debe exceder 30 caracteres.'],
            ]);
        }
        $operation = $this->nullableTrim($payload['operation_number'] ?? null);
        if ($operation !== null && mb_strlen($operation) > 60) {
            throw ValidationException::withMessages([
                'operation_number' => ['El número de operación no debe exceder 60 caracteres.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($recordId, $payload, $userId, $scope, $date, $constancia, $operation) {
            $record = DetractionRecord::query()->whereKey($recordId)->lockForUpdate()->first();
            if (!$record) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(DetractionRecord::class, [$recordId]);
            }

            $this->assertRecordAccess($record, $scope);

            if ($record->status !== DetractionRecord::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'record' => ['Esta detracción ya tiene un depósito registrado.'],
                ]);
            }

            $source = $this->loadSource($record);
            if ($source === null || !in_array((string) $source->state_type_id, ReceivablePayableService::ACCEPTED_STATE_TYPES, true)) {
                throw ValidationException::withMessages([
                    'record' => ['El comprobante de origen está anulado o no existe.'],
                ]);
            }

            $issueDate = $this->normalizeDate($source->date_of_issue);
            if ($issueDate !== null && $date < $issueDate) {
                throw ValidationException::withMessages([
                    'date' => ['La fecha del depósito no puede ser anterior a la emisión del comprobante.'],
                ]);
            }

            if ($record->side === DetractionRecord::SIDE_PAYABLE && $constancia === null) {
                throw ValidationException::withMessages([
                    'constancia_number' => ['Ingrese el número de la constancia de depósito de la detracción.'],
                ]);
            }

            $accountId = !empty($payload['bank_account_id']) ? (int) $payload['bank_account_id'] : null;
            if ($accountId === null && $record->side === DetractionRecord::SIDE_RECEIVABLE) {
                $accountId = $this->resolveDefaultBnAccountId($record->bn_account_number, $scope);
            }
            if ($accountId === null) {
                throw ValidationException::withMessages([
                    'bank_account_id' => [
                        $record->side === DetractionRecord::SIDE_RECEIVABLE
                            ? 'No se encontró una cuenta de detracciones del Banco de la Nación. Registre una cuenta con ese tipo o selecciónela.'
                            : 'Seleccione la cuenta desde la que realizó el depósito.',
                    ],
                ]);
            }

            // Same account authorization for explicit and auto-resolved accounts.
            $this->assertAccountAccess($accountId, $scope);

            $party = $this->loadParty($record, $source);

            $movement = $this->movementService->createDetractionDeposit([
                'side' => $record->side,
                'bank_account_id' => $accountId,
                'amount_pen' => (float) $record->amount_pen,
                'date' => $date,
                'establishment_id' => (int) ($record->establishment_id ?? ($scope['establishment_id'] ?? 0)),
                'operation_number' => $operation,
                'source_id' => (int) $record->getKey(),
                'counterparty_name' => $party['name'],
                'counterparty_document' => $party['number'],
                'description' => ($record->side === DetractionRecord::SIDE_RECEIVABLE
                        ? 'Detracción recibida de '
                        : 'Detracción depositada a ')
                    . trim((string) $source->series) . '-' . trim((string) $source->number)
                    . ($constancia !== null ? ' (constancia ' . $constancia . ')' : ''),
            ], $userId);

            $record->deposited_at = $date;
            $record->constancia_number = $constancia;
            $record->operation_number = $operation;
            $record->deposit_movement_id = $movement->getKey();
            $record->status = $record->side === DetractionRecord::SIDE_RECEIVABLE
                ? DetractionRecord::STATUS_RECEIVED
                : DetractionRecord::STATUS_DEPOSITED;
            $record->save();

            return $record;
        });
    }

    /**
     * Reverts a registered deposit: voids its movement (restoring the balance) and sets the record back to pending.
     */
    public function revertDeposit(int $recordId, string $reason, int $userId, array $scope = []): DetractionRecord
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'void_reason' => ['El motivo de la reversión es obligatorio (mínimo 3 caracteres).'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($recordId, $reason, $userId, $scope) {
            $record = DetractionRecord::query()->whereKey($recordId)->lockForUpdate()->first();
            if (!$record) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(DetractionRecord::class, [$recordId]);
            }

            $this->assertRecordAccess($record, $scope);

            if ($record->status === DetractionRecord::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'record' => ['Esta detracción no tiene un depósito registrado.'],
                ]);
            }

            if ($record->deposit_movement_id) {
                $depositMovement = TreasuryMovement::query()->whereKey((int) $record->deposit_movement_id)->first();
                if ($depositMovement && $depositMovement->destination_type === BankAccount::class) {
                    // A non-admin must not revert a deposit that credited/debited another establishment's account.
                    $this->assertAccountAccess((int) $depositMovement->destination_id, $scope);
                }

                $this->movementService->void((int) $record->deposit_movement_id, $reason, $userId, true);
            }

            $record->deposited_at = null;
            $record->constancia_number = null;
            $record->operation_number = null;
            $record->deposit_movement_id = null;
            $record->status = DetractionRecord::STATUS_PENDING;
            $record->save();

            return $record;
        });
    }

    /**
     * Registers an approved release of SPOT funds (Formulario 1697) from the BN account to an own account.
     * The release windows are NOT enforced: they are informational (config treasury.detraction_release_info).
     *
     * @return array{out: \Modules\Treasury\Models\TreasuryMovement, in: \Modules\Treasury\Models\TreasuryMovement, transfer_group: string}
     */
    public function registerRelease(array $payload, int $userId): array
    {
        return $this->movementService->createDetractionRelease($payload, $userId);
    }

    // ------------------------------------------------------------------------------------------------
    // Alerts
    // ------------------------------------------------------------------------------------------------

    /**
     * Overdue (pending past due date) detractions for the Resumen alerts. Reads existing records only.
     *
     * @return array{count: int, amount_pen: float, items: array}
     */
    public function getOverdueAlerts(array $filters = [], ?string $asOfDate = null, int $maxItems = 10): array
    {
        $result = ['count' => 0, 'amount_pen' => 0.0, 'items' => []];

        foreach ([DetractionRecord::SIDE_PAYABLE, DetractionRecord::SIDE_RECEIVABLE] as $side) {
            $list = $this->listRecords($side, array_merge($filters, [
                'status' => DetractionRecord::STATUS_OVERDUE,
                'per_page' => $maxItems,
                'page' => 1,
            ]), $asOfDate);

            $count = (int) $list['totals']['by_status'][DetractionRecord::STATUS_OVERDUE]['count'];
            $result['count'] += $count;
            $result['amount_pen'] = Money::round(
                $result['amount_pen'] + (float) $list['totals']['by_status'][DetractionRecord::STATUS_OVERDUE]['amount_pen']
            );

            foreach ($list['rows'] as $row) {
                if (count($result['items']) < $maxItems) {
                    $result['items'][] = [
                        'id' => $row['id'],
                        'side' => $side,
                        'number_full' => $row['number_full'],
                        'party_name' => $row['party_name'],
                        'amount_pen' => $row['amount_pen'],
                        'due_date' => $row['due_date'],
                        'days_overdue' => $row['days_overdue'],
                        'due_date_is_estimate' => $row['due_date_is_estimate'],
                    ];
                }
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------

    public function effectiveStatus(string $storedStatus, ?string $dueDate, string $asOf, bool $isEstimate = false): string
    {
        if ($storedStatus !== DetractionRecord::STATUS_PENDING) {
            return $storedStatus;
        }

        $cutoff = $isEstimate ? $this->graceCutoff($asOf) : $asOf;

        return ($dueDate !== null && $dueDate < $cutoff)
            ? DetractionRecord::STATUS_OVERDUE
            : DetractionRecord::STATUS_PENDING;
    }

    /**
     * Non-admin ownership: only the user who issued/registered the source document, in their own establishment.
     */
    public function assertRecordAccess(DetractionRecord $record, array $scope): void
    {
        $isAdmin = array_key_exists('is_admin', $scope) ? (bool) $scope['is_admin'] : true;
        if ($isAdmin) {
            return;
        }

        $userId = (int) ($scope['user_id'] ?? 0);
        $establishmentId = (int) ($scope['establishment_id'] ?? 0);

        // A non-admin without an establishment must never match NULL-establishment records.
        if ($userId <= 0
            || $establishmentId <= 0
            || (int) $record->user_id !== $userId
            || (int) $record->establishment_id !== $establishmentId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('No tiene permiso para gestionar esta detracción.');
        }
    }

    /**
     * Account ownership for non-admins: the account must belong to their establishment or be shared (NULL
     * establishment), same rule the controller applies to explicit accounts.
     */
    public function assertAccountAccess(int $accountId, array $scope): void
    {
        $isAdmin = array_key_exists('is_admin', $scope) ? (bool) $scope['is_admin'] : true;
        if ($isAdmin) {
            return;
        }

        $establishmentId = (int) ($scope['establishment_id'] ?? 0);
        $account = BankAccount::query()->whereKey($accountId)->first();

        if ($establishmentId <= 0
            || !$account
            || (!empty($account->establishment_id) && (int) $account->establishment_id !== $establishmentId)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('No tiene permiso para operar sobre una cuenta bancaria de otra sede.');
        }
    }

    private function resolveDefaultBnAccountId(?string $bnAccountNumber, array $scope = []): ?int
    {
        $query = BankAccount::query()
            ->where('account_type', 'detraction_bn')
            ->where('status', 1)
            ->where('currency_type_id', 'PEN');

        $isAdmin = array_key_exists('is_admin', $scope) ? (bool) $scope['is_admin'] : true;
        if (!$isAdmin) {
            $establishmentId = (int) ($scope['establishment_id'] ?? 0);
            $query->where(function ($q) use ($establishmentId) {
                $q->whereNull('establishment_id')->orWhere('establishment_id', $establishmentId);
            });
        }

        $accounts = $query->orderBy('id')->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        if ($bnAccountNumber !== null) {
            $needle = preg_replace('/[^0-9A-Za-z]/', '', $bnAccountNumber);
            foreach ($accounts as $account) {
                if ($needle !== '' && preg_replace('/[^0-9A-Za-z]/', '', (string) $account->number) === $needle) {
                    return (int) $account->id;
                }
            }
        }

        return $accounts->count() === 1 ? (int) $accounts->first()->id : null;
    }

    private function loadSource(DetractionRecord $record): ?object
    {
        $table = $record->source_type === self::SOURCE_DOCUMENT ? 'documents' : 'purchases';

        return DB::connection('tenant')->table($table)->where('id', $record->source_id)->first();
    }

    /**
     * @return array{name: ?string, number: ?string}
     */
    private function loadParty(DetractionRecord $record, object $source): array
    {
        $partyId = $record->source_type === self::SOURCE_DOCUMENT
            ? ($source->customer_id ?? null)
            : ($source->supplier_id ?? null);

        if (!$partyId || !$this->hasTable('persons')) {
            return ['name' => null, 'number' => null];
        }

        $person = DB::connection('tenant')->table('persons')->where('id', $partyId)->first(['name', 'number']);

        return $person
            ? ['name' => $person->name, 'number' => $person->number]
            : ['name' => null, 'number' => null];
    }

    /**
     * @param array<int, int> $purchaseIds
     * @return array<int, string>
     */
    private function loadFirstPaymentDates(array $purchaseIds): array
    {
        if (empty($purchaseIds) || !$this->hasTable('purchase_payments')) {
            return [];
        }

        $rows = DB::connection('tenant')
            ->table('purchase_payments')
            ->select('purchase_id', DB::raw('MIN(date_of_payment) as first_date'))
            ->whereIn('purchase_id', $purchaseIds)
            ->groupBy('purchase_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $date = $this->normalizeDate($row->first_date);
            if ($date !== null) {
                $map[(int) $row->purchase_id] = $date;
            }
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function loadDetractionDescriptions(): array
    {
        if (!$this->hasTable('cat_detraction_types')) {
            return [];
        }

        return DB::connection('tenant')->table('cat_detraction_types')->pluck('description', 'id')->all();
    }

    private function emptyTotals(): array
    {
        $bucket = ['count' => 0, 'amount_pen' => 0.0];

        return [
            'count' => 0,
            'amount_pen' => 0.0,
            'by_status' => [
                DetractionRecord::STATUS_PENDING => $bucket,
                DetractionRecord::STATUS_OVERDUE => $bucket,
                DetractionRecord::STATUS_DEPOSITED => $bucket,
                DetractionRecord::STATUS_RECEIVED => $bucket,
            ],
        ];
    }

    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function nullableTrim($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function hasTable(string $table): bool
    {
        if (isset($this->schemaCache['t:' . $table])) {
            return $this->schemaCache['t:' . $table];
        }

        if (!Model::getConnectionResolver()) {
            return false;
        }

        try {
            return $this->schemaCache['t:' . $table] = DB::connection('tenant')->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = 'c:' . $table . '.' . $column;
        if (isset($this->schemaCache[$key])) {
            return $this->schemaCache[$key];
        }

        if (!$this->hasTable($table)) {
            return false;
        }

        try {
            return $this->schemaCache[$key] = DB::connection('tenant')->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
