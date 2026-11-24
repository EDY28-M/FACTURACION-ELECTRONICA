<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\GlobalPayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use Modules\Treasury\Models\BankStatement;
use Modules\Treasury\Models\BankStatementLine;
use Modules\Treasury\Models\BankStatementLineMatch;
use Modules\Treasury\Models\BankStatementMapping;
use Modules\Treasury\Models\TreasuryConcept;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\DatabaseErrors;
use Modules\Treasury\Support\Money;

/**
 * Bank reconciliation (Phase 5): statement import, automatic/manual matching against Treasury movements and
 * Finance global payments, creation of missing movements from statement lines, and closing with a snapshot.
 *
 * Rules:
 * - Never calls auth(): every write receives an explicit $userId and a $scope
 *   (['is_admin' => bool, 'user_id' => ?int, 'establishment_id' => ?int]).
 * - Every multi-row write runs in DB::connection('tenant')->transaction(). Global lock order is always
 *   bank account -> statement -> lines -> system items (movements / global payments). MovementService::void() follows
 *   the same order for the part it shares (destination account first, then the movement rows), so a void racing a
 *   reconciliation cannot deadlock. Legacy Finance payment controllers do not take part in this order.
 * - global_payments is never altered (D5): the match lives in `bank_statement_line_matches`.
 *
 * Sign convention: statement lines and candidates use a SIGNED amount (+ inflow into the account, - outflow).
 *
 * Reconciliation identity used for the closing (cumulative over every statement of the account up to this one):
 *   adjusted bank = closing - ignored lines - "ahead" matched items (dated after the period) + in-transit items
 *   difference    = adjusted bank - system balance as of period_to
 * where in-transit items are system items dated from the first statement's start up to period_to that are not matched
 * to any line. It is zero exactly when the first statement's opening balance agrees with the books and every line is
 * matched or explained (ignored with a reason).
 *
 * Book adjustments (credit notes): TreasuryBalanceService deducts the credit notes of every document paid into the
 * account although no payment/movement exists for them. They are exposed as synthetic, NON-matchable items
 * (type `credit_note_adjustment`) that reduce the books but never appear on the bank statement, so they enter the
 * identity as explained book items: adjusted bank = ... + in-transit + adjustments (negative).
 *
 * Rounding: the in-transit total is anchored to the aggregate balance (balance as of period_to minus balance before
 * the first statement, minus adjustments, minus every item of the range) instead of trusting only the per-item
 * rounded amounts; the drift is only accepted up to `treasury.reconciliation.rounding_tolerance` per converted item
 * and is recorded in the snapshot.
 *
 * Open item (not enforced): legacy Finance payment edit/delete endpoints do not know about closed reconciliations.
 * Instead, summary()/close()/reopen() re-verify every match against current data (staleMatches()) and flag the
 * statement as stale.
 */
class ReconciliationService
{
    public const MATCHABLE_TYPES = [
        'movement' => TreasuryMovement::class,
        'global_payment' => GlobalPayment::class,
    ];

    /** Kinds that can be created from a statement line, with the sign of the line they accept (0 = both). */
    public const LINE_MOVEMENT_KINDS = [
        'bank_fee' => -1,
        'itf' => -1,
        'interest' => 1,
        'income' => 1,
        'expense' => -1,
        'adjustment' => 0,
    ];

    /** Default concepts by kind (type + description seeded by the Treasury migration). */
    private const DEFAULT_CONCEPTS = [
        'bank_fee' => ['expense', 'Comisión bancaria'],
        'itf' => ['expense', 'Comisión bancaria'],
        'interest' => ['income', 'Intereses ganados'],
    ];

    private const PAYMENT_SPECS = [
        ['class' => DocumentPayment::class, 'pay' => 'document_payments', 'fk' => 'document_id', 'parent' => 'documents', 'sign' => 1, 'has_state' => true, 'has_changed' => false, 'has_currency' => true, 'label' => 'Cobro CPE', 'number_cols' => ['series', 'number']],
        ['class' => SaleNotePayment::class, 'pay' => 'sale_note_payments', 'fk' => 'sale_note_id', 'parent' => 'sale_notes', 'sign' => 1, 'has_state' => true, 'has_changed' => true, 'has_currency' => true, 'label' => 'Cobro nota de venta', 'number_cols' => ['series', 'number']],
        ['class' => QuotationPayment::class, 'pay' => 'quotation_payments', 'fk' => 'quotation_id', 'parent' => 'quotations', 'sign' => 1, 'has_state' => true, 'has_changed' => true, 'has_currency' => true, 'label' => 'Cobro cotización', 'number_cols' => []],
        ['class' => ContractPayment::class, 'pay' => 'contract_payments', 'fk' => 'contract_id', 'parent' => 'contracts', 'sign' => 1, 'has_state' => true, 'has_changed' => true, 'has_currency' => true, 'label' => 'Cobro contrato', 'number_cols' => []],
        ['class' => IncomePayment::class, 'pay' => 'income_payments', 'fk' => 'income_id', 'parent' => 'income', 'sign' => 1, 'has_state' => true, 'has_changed' => false, 'has_currency' => true, 'label' => 'Ingreso', 'number_cols' => []],
        ['class' => TechnicalServicePayment::class, 'pay' => 'technical_service_payments', 'fk' => 'technical_service_id', 'parent' => 'technical_services', 'sign' => 1, 'has_state' => false, 'has_changed' => false, 'has_currency' => false, 'label' => 'Cobro servicio técnico', 'number_cols' => []],
        ['class' => PurchasePayment::class, 'pay' => 'purchase_payments', 'fk' => 'purchase_id', 'parent' => 'purchases', 'sign' => -1, 'has_state' => true, 'has_changed' => false, 'has_currency' => true, 'label' => 'Pago de compra', 'number_cols' => ['series', 'number']],
        ['class' => ExpensePayment::class, 'pay' => 'expense_payments', 'fk' => 'expense_id', 'parent' => 'expenses', 'sign' => -1, 'has_state' => true, 'has_changed' => false, 'has_currency' => true, 'label' => 'Pago de gasto', 'number_cols' => ['number']],
    ];

    private $movementService;
    private $balanceService;

    public function __construct(MovementService $movementService, TreasuryBalanceService $balanceService)
    {
        $this->movementService = $movementService;
        $this->balanceService = $balanceService;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Access
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Non-admins may only operate on accounts of their establishment or shared (NULL establishment) accounts, and a
     * non-admin without an establishment is denied everywhere (W5 of Phase 4).
     */
    public function assertAccountAccess(int $accountId, array $scope): void
    {
        if ($this->isAdminScope($scope)) {
            return;
        }

        $establishmentId = (int) ($scope['establishment_id'] ?? 0);
        $account = BankAccount::query()->whereKey($accountId)->first();

        if ($establishmentId <= 0
            || !$account
            || (!empty($account->establishment_id) && (int) $account->establishment_id !== $establishmentId)) {
            throw new AuthorizationException('No tiene permiso para operar sobre una cuenta bancaria de otra sede.');
        }
    }

    public function isAdminScope(array $scope): bool
    {
        return array_key_exists('is_admin', $scope) && (bool) $scope['is_admin'];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Statements: import, list, discard
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Creates a draft statement with its lines. Rejects an overlapping period for the same account.
     *
     * @param array{period_from: string, period_to: string, opening_balance: mixed, closing_balance: mixed, accept_unbalanced?: bool} $meta
     * @param array<int, array{line_no: int, date: string, description: string, reference: ?string, amount: float}> $lines
     */
    public function createStatement(int $accountId, array $meta, array $lines, ?string $fileName, ?string $filePath, int $userId, array $scope): BankStatement
    {
        $this->assertUser($userId);

        $from = $this->requireDate($meta['period_from'] ?? null, 'period_from', 'El inicio del período');
        $to = $this->requireDate($meta['period_to'] ?? null, 'period_to', 'El fin del período');
        if ($from > $to) {
            throw ValidationException::withMessages(['period_to' => ['El fin del período no puede ser anterior al inicio.']]);
        }

        $opening = $this->requireNumber($meta['opening_balance'] ?? null, 'opening_balance', 'El saldo inicial');
        $closing = $this->requireNumber($meta['closing_balance'] ?? null, 'closing_balance', 'El saldo final');

        if (empty($lines)) {
            throw ValidationException::withMessages(['file' => ['El extracto no contiene movimientos para importar.']]);
        }

        $outside = 0;
        $net = 0.0;
        foreach ($lines as $line) {
            if ($line['date'] < $from || $line['date'] > $to) {
                $outside++;
            }
            $net += (float) $line['amount'];
        }
        if ($outside > 0) {
            throw ValidationException::withMessages([
                'period_from' => ["{$outside} movimiento(s) del archivo tienen una fecha fuera del período indicado ({$from} a {$to})."],
            ]);
        }

        $expectedClosing = Money::round($opening + $net);
        $unbalancedGap = Money::round($closing - $expectedClosing);
        $importedUnbalanced = !empty($meta['accept_unbalanced']) && $this->cents($unbalancedGap) !== 0;
        if (empty($meta['accept_unbalanced']) && $this->cents($expectedClosing) !== $this->cents($closing)) {
            throw ValidationException::withMessages([
                'closing_balance' => [
                    'El saldo inicial más los movimientos (' . Money::format($expectedClosing) . ') no coincide con el saldo final indicado ('
                    . Money::format($closing) . '). Revise el archivo, el mapeo o los saldos.',
                ],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($accountId, $from, $to, $opening, $closing, $lines, $fileName, $filePath, $userId, $scope, $importedUnbalanced, $unbalancedGap) {
            $account = BankAccount::query()->whereKey($accountId)->lockForUpdate()->first();
            if (!$account) {
                throw ValidationException::withMessages(['bank_account_id' => ['La cuenta bancaria seleccionada no existe.']]);
            }
            $this->assertAccountAccess($accountId, $scope);

            $overlap = BankStatement::query()
                ->where('bank_account_id', $accountId)
                ->whereDate('period_from', '<=', $to)
                ->whereDate('period_to', '>=', $from)
                ->orderBy('period_from')
                ->first();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'period_from' => [
                        'Ya existe un extracto de esta cuenta que se superpone con el período indicado ('
                        . $overlap->period_from->format('Y-m-d') . ' a ' . $overlap->period_to->format('Y-m-d')
                        . '). Descarte el extracto anterior si desea importarlo de nuevo.',
                    ],
                ]);
            }

            $statement = BankStatement::create([
                'bank_account_id' => $accountId,
                'period_from' => $from,
                'period_to' => $to,
                'opening_balance' => $opening,
                'closing_balance' => $closing,
                'file_name' => $fileName !== null ? mb_substr($fileName, 0, 255) : null,
                'file_path' => $filePath,
                'imported_by' => $userId,
                'status' => BankStatement::STATUS_DRAFT,
                'imported_unbalanced' => $importedUnbalanced,
                'unbalanced_gap' => $importedUnbalanced ? $unbalancedGap : null,
            ]);

            $now = date('Y-m-d H:i:s');
            $rows = [];
            foreach ($lines as $i => $line) {
                $rows[] = [
                    'bank_statement_id' => $statement->id,
                    'line_no' => $i + 1,
                    'date' => $line['date'],
                    'description' => mb_substr((string) $line['description'], 0, 500),
                    'reference' => $line['reference'] !== null ? mb_substr((string) $line['reference'], 0, 100) : null,
                    'amount' => Money::round((float) $line['amount']),
                    'status' => BankStatementLine::STATUS_UNMATCHED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 300) as $chunk) {
                DB::connection('tenant')->table('bank_statement_lines')->insert($chunk);
            }

            return $statement;
        });
    }

    /**
     * Discards a DRAFT statement (and its matches). Returns the stored file path so the caller can delete the file.
     */
    public function discardStatement(int $statementId, int $userId, array $scope): ?string
    {
        $this->assertUser($userId);

        return DB::connection('tenant')->transaction(function () use ($statementId, $scope) {
            [$statement] = $this->lockContext($statementId, $scope);
            $this->assertDraft($statement);

            $lineIds = BankStatementLine::query()->where('bank_statement_id', $statement->id)->pluck('id')->all();
            if (!empty($lineIds)) {
                TreasuryMovement::query()->whereIn('bank_statement_line_id', $lineIds)->update(['bank_statement_line_id' => null]);
                BankStatementLineMatch::query()->whereIn('bank_statement_line_id', $lineIds)->delete();
                BankStatementLine::query()->where('bank_statement_id', $statement->id)->delete();
            }

            $path = $statement->file_path;
            $statement->delete();

            return $path;
        });
    }

    /**
     * @param array{bank_account_id?: ?int, status?: ?string, date_start?: ?string, date_end?: ?string, page?: int, per_page?: int} $filters
     */
    public function listStatements(array $filters, array $scope): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));

        $query = BankStatement::query()->orderByDesc('period_to')->orderByDesc('id');
        $this->applyAccountScope($query, 'bank_account_id', $scope);

        if (!empty($filters['bank_account_id'])) {
            $query->where('bank_account_id', (int) $filters['bank_account_id']);
        }
        if (!empty($filters['status']) && in_array($filters['status'], [BankStatement::STATUS_DRAFT, BankStatement::STATUS_CLOSED], true)) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['date_start'])) {
            $query->whereDate('period_to', '>=', $filters['date_start']);
        }
        if (!empty($filters['date_end'])) {
            $query->whereDate('period_from', '<=', $filters['date_end']);
        }

        $total = (clone $query)->count();
        $statements = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $counts = $this->lineCounts($statements->pluck('id')->all());
        $accounts = $this->accountDescriptions($statements->pluck('bank_account_id')->unique()->all());

        $rows = $statements->map(function (BankStatement $s) use ($counts, $accounts) {
            return $this->presentStatement($s, $counts[$s->id] ?? null, $accounts[$s->bank_account_id] ?? null);
        })->values()->all();

        return [
            'rows' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function getStatement(int $statementId, array $scope): array
    {
        $statement = BankStatement::query()->whereKey($statementId)->firstOrFail();
        $this->assertAccountAccess((int) $statement->bank_account_id, $scope);

        $counts = $this->lineCounts([$statement->id]);
        $accounts = $this->accountDescriptions([$statement->bank_account_id]);

        return $this->presentStatement($statement, $counts[$statement->id] ?? null, $accounts[$statement->bank_account_id] ?? null);
    }

    /**
     * @param array{status?: ?string, search?: ?string, page?: int, per_page?: int} $filters
     */
    public function listLines(int $statementId, array $filters, array $scope): array
    {
        $statement = BankStatement::query()->whereKey($statementId)->firstOrFail();
        $this->assertAccountAccess((int) $statement->bank_account_id, $scope);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($filters['per_page'] ?? 100)));

        $query = BankStatementLine::query()
            ->where('bank_statement_id', $statement->id)
            ->orderBy('line_no');

        if (!empty($filters['status']) && in_array($filters['status'], [BankStatementLine::STATUS_UNMATCHED, BankStatementLine::STATUS_MATCHED, BankStatementLine::STATUS_IGNORED], true)) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['search'])) {
            $needle = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $filters['search'])) . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('description', 'like', $needle)->orWhere('reference', 'like', $needle);
            });
        }

        $total = (clone $query)->count();
        $lines = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $matches = BankStatementLineMatch::query()
            ->whereIn('bank_statement_line_id', $lines->pluck('id')->all())
            ->whereNull('released_at')
            ->get()
            ->groupBy('bank_statement_line_id');

        $described = $this->describeMatchables($statement, $matches->flatten(1));

        $rows = $lines->map(function (BankStatementLine $line) use ($matches, $described) {
            $items = [];
            foreach ($matches->get($line->id, collect()) as $match) {
                $alias = $match->matchable_type === TreasuryMovement::class ? 'movement' : 'global_payment';
                $info = $described[$match->matchable_type . ':' . $match->matchable_id] ?? [];
                $items[] = [
                    'type' => $alias,
                    'id' => (int) $match->matchable_id,
                    'amount' => Money::round((float) $match->amount),
                    'date' => $match->item_date ? $match->item_date->format('Y-m-d') : null,
                    'label' => $info['label'] ?? null,
                    'description' => $info['description'] ?? null,
                ];
            }

            return [
                'id' => (int) $line->id,
                'line_no' => (int) $line->line_no,
                'date' => $line->date->format('Y-m-d'),
                'description' => $line->description,
                'reference' => $line->reference,
                'amount' => Money::round((float) $line->amount),
                'status' => $line->status,
                'ignore_reason' => $line->ignore_reason,
                'items' => $items,
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    // ------------------------------------------------------------------------------------------------------------
    // Candidates and automatic matching
    // ------------------------------------------------------------------------------------------------------------

    /**
     * System items of the statement account that can still be matched: active, not matched to any line, dated within
     * the statement period +/- the configured window.
     *
     * @param array{line_id?: ?int, search?: ?string, limit?: int} $filters
     */
    public function listCandidates(int $statementId, array $filters, array $scope): array
    {
        $statement = BankStatement::query()->whereKey($statementId)->firstOrFail();
        $this->assertAccountAccess((int) $statement->bank_account_id, $scope);

        $account = BankAccount::query()->whereKey($statement->bank_account_id)->firstOrFail();
        $window = $this->windowDays();
        [$from, $to] = $this->candidateRange($statement, $window);

        $items = $this->loadSystemItems((int) $account->id, (string) $account->currency_type_id, $from, $to);
        $matchedKeys = $this->activeMatchKeys((int) $account->id);

        $line = !empty($filters['line_id'])
            ? BankStatementLine::query()->where('bank_statement_id', $statement->id)->whereKey((int) $filters['line_id'])->first()
            : null;

        $search = isset($filters['search']) ? mb_strtolower(trim((string) $filters['search'])) : '';
        $limit = min(500, max(1, (int) ($filters['limit'] ?? 300)));

        $result = [];
        foreach ($items as $item) {
            if (isset($matchedKeys[$item['key']])) {
                continue;
            }
            if ($search !== '' && mb_strpos(mb_strtolower($item['label'] . ' ' . $item['description'] . ' ' . ($item['reference'] ?? '')), $search) === false) {
                continue;
            }

            $suggested = false;
            if ($line) {
                $suggested = $this->cents($item['amount']) === $this->cents((float) $line->amount)
                    && abs($this->daysBetween($item['date'], $line->date->format('Y-m-d'))) <= $window;
            }
            $item['suggested'] = $suggested;
            $result[] = $item;
        }

        usort($result, function ($a, $b) {
            if ($a['suggested'] !== $b['suggested']) {
                return $a['suggested'] ? -1 : 1;
            }

            return [$a['date'], $a['key']] <=> [$b['date'], $b['key']];
        });

        $truncated = count($result) > $limit;

        return [
            'rows' => array_map(function ($item) {
                unset($item['key']);

                return $item;
            }, array_slice($result, 0, $limit)),
            'truncated' => $truncated,
            'window_days' => $window,
            'range' => ['from' => $from, 'to' => $to],
        ];
    }

    /**
     * Automatic one-to-one matching: exact signed amount + date within +/- N days + (when available) reference.
     * Ambiguous lines (several equally good candidates) are left for manual matching. Never touches other accounts,
     * voided items, or items already matched.
     *
     * @return array{matched: int, ambiguous: int, unmatched: int, conflicts: int}
     */
    public function autoMatch(int $statementId, int $userId, array $scope): array
    {
        $this->assertUser($userId);

        return DB::connection('tenant')->transaction(function () use ($statementId, $userId, $scope) {
            [$statement, $account] = $this->lockContext($statementId, $scope);
            $this->assertDraft($statement);

            $window = $this->windowDays();
            $requireReference = (bool) $this->configValue('treasury.reconciliation.require_reference_when_available', false);
            $minReference = (int) $this->configValue('treasury.reconciliation.min_reference_length', 4);

            [$from, $to] = $this->candidateRange($statement, $window);
            $items = $this->loadSystemItems((int) $account->id, (string) $account->currency_type_id, $from, $to);
            $matchedKeys = $this->activeMatchKeys((int) $account->id);

            $this->afterAutoMatchItemsLoaded($items);

            $available = [];
            foreach ($items as $item) {
                if (isset($matchedKeys[$item['key']]) || !empty($item['converted'])) {
                    continue; // converted (cross-currency) items are never auto-matched
                }
                $available[$item['key']] = $item;
            }

            $lines = BankStatementLine::query()
                ->where('bank_statement_id', $statement->id)
                ->where('status', BankStatementLine::STATUS_UNMATCHED)
                ->orderBy('date')
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();

            $matched = 0;
            $ambiguous = 0;
            $conflicts = 0;

            foreach ($lines as $line) {
                $lineCents = $this->cents((float) $line->amount);
                $lineDate = $line->date->format('Y-m-d');
                $haystack = $this->normalizeReference($line->reference . ' ' . $line->description);

                $eligible = [];
                foreach ($available as $item) {
                    if ($this->cents($item['amount']) !== $lineCents) {
                        continue;
                    }
                    $distance = abs($this->daysBetween($item['date'], $lineDate));
                    if ($distance > $window) {
                        continue;
                    }

                    $referenceHit = false;
                    $hint = $this->normalizeReference((string) ($item['reference'] ?? ''));
                    $hasHint = strlen($hint) >= $minReference;
                    if ($hasHint) {
                        $stripped = ltrim($hint, '0');
                        $referenceHit = strpos($haystack, $hint) !== false
                            || ($stripped !== '' && strlen($stripped) >= $minReference && strpos($haystack, $stripped) !== false);
                    }
                    if ($requireReference && $hasHint && !$referenceHit) {
                        continue;
                    }

                    $eligible[] = ['item' => $item, 'distance' => $distance, 'reference_hit' => $referenceHit];
                }

                if (empty($eligible)) {
                    continue;
                }

                $strong = array_values(array_filter($eligible, function ($e) {
                    return $e['reference_hit'];
                }));
                $pool = !empty($strong) ? $strong : $eligible;

                $pick = null;
                if (count($pool) === 1) {
                    $pick = $pool[0];
                } elseif (!empty($strong)) {
                    // Several reference hits: only the unique closest date is safe.
                    usort($pool, function ($a, $b) {
                        return $a['distance'] <=> $b['distance'];
                    });
                    if ($pool[0]['distance'] < $pool[1]['distance']) {
                        $pick = $pool[0];
                    }
                }

                if ($pick === null) {
                    $ambiguous++;
                    continue;
                }

                // The item was loaded before the loop: re-lock and re-validate it right before attaching (it may have
                // been voided, deleted, moved or matched meanwhile). Skip it and leave the line unmatched otherwise.
                $fresh = $pick['item']['type'] === 'movement'
                    ? $this->resolveMovementItem((int) $pick['item']['id'], $account)
                    : $this->resolveGlobalPaymentItem((int) $pick['item']['id'], $account);
                if ($fresh === null
                    || $this->cents($fresh['amount']) !== $lineCents
                    || $this->cents($fresh['amount']) !== $this->cents($pick['item']['amount'])
                    || $fresh['date'] !== $pick['item']['date']) {
                    unset($available[$pick['item']['key']]);
                    $conflicts++;
                    continue;
                }

                try {
                    $this->attachItems($line, [$fresh], $userId);
                } catch (ValidationException $e) {
                    $conflicts++;
                    continue;
                }

                unset($available[$pick['item']['key']]);
                $matched++;
            }

            $remaining = BankStatementLine::query()
                ->where('bank_statement_id', $statement->id)
                ->where('status', BankStatementLine::STATUS_UNMATCHED)
                ->count();

            return ['matched' => $matched, 'ambiguous' => $ambiguous, 'unmatched' => $remaining, 'conflicts' => $conflicts];
        });
    }

    /**
     * Seam called once the auto-match candidates are loaded and before any of them is attached. Does nothing; it
     * exists so tests can change the books in between and prove each pick is re-validated.
     *
     * @param array<int, array> $items
     */
    protected function afterAutoMatchItemsLoaded(array $items): void
    {
    }

    // ------------------------------------------------------------------------------------------------------------
    // Manual matching, unmatching, ignoring
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Manual match of one line to one or several system items. The signed sum of the items must equal the line
     * amount exactly (tolerance 0.00).
     *
     * @param array<int, array{type: string, id: int}> $items
     */
    public function matchLine(int $lineId, array $items, int $userId, array $scope): BankStatementLine
    {
        $this->assertUser($userId);

        if (empty($items)) {
            throw ValidationException::withMessages(['items' => ['Seleccione al menos un movimiento del sistema.']]);
        }
        if (count($items) > 50) {
            throw ValidationException::withMessages(['items' => ['No puede emparejar más de 50 movimientos con una sola línea.']]);
        }

        return DB::connection('tenant')->transaction(function () use ($lineId, $items, $userId, $scope) {
            [$statement, $account, $line] = $this->lockContextForLine($lineId, $scope);
            $this->assertDraft($statement);
            $this->assertLineStatus($line, BankStatementLine::STATUS_UNMATCHED);

            $resolved = [];
            $seen = [];
            foreach ($items as $raw) {
                $alias = (string) ($raw['type'] ?? '');
                $id = (int) ($raw['id'] ?? 0);
                if (!isset(self::MATCHABLE_TYPES[$alias]) || $id <= 0) {
                    throw ValidationException::withMessages(['items' => ['Uno de los elementos seleccionados no es válido.']]);
                }
                $key = self::MATCHABLE_TYPES[$alias] . ':' . $id;
                if (isset($seen[$key])) {
                    throw ValidationException::withMessages(['items' => ['Un mismo movimiento no puede seleccionarse dos veces.']]);
                }
                $seen[$key] = true;

                $item = $alias === 'movement'
                    ? $this->resolveMovementItem($id, $account)
                    : $this->resolveGlobalPaymentItem($id, $account);

                if ($item === null) {
                    throw ValidationException::withMessages([
                        'items' => ['Uno de los movimientos seleccionados no existe, está anulado, ya está emparejado o no pertenece a la cuenta del extracto.'],
                    ]);
                }
                $resolved[] = $item;
            }

            $this->attachItems($line, $resolved, $userId);

            return $line->fresh();
        });
    }

    public function unmatchLine(int $lineId, int $userId, array $scope): BankStatementLine
    {
        $this->assertUser($userId);

        return DB::connection('tenant')->transaction(function () use ($lineId, $userId, $scope) {
            [$statement, , $line] = $this->lockContextForLine($lineId, $scope);
            $this->assertDraft($statement);
            $this->assertLineStatus($line, BankStatementLine::STATUS_MATCHED);

            $this->releaseMatches([(int) $line->id], $userId);

            $line->status = BankStatementLine::STATUS_UNMATCHED;
            $line->save();

            return $line;
        });
    }

    public function ignoreLine(int $lineId, string $reason, int $userId, array $scope): BankStatementLine
    {
        $this->assertUser($userId);

        $clean = trim($reason);
        if (mb_strlen($clean) < 3) {
            throw ValidationException::withMessages(['reason' => ['El motivo para ignorar la línea es obligatorio (mínimo 3 caracteres).']]);
        }
        if (mb_strlen($clean) > 255) {
            throw ValidationException::withMessages(['reason' => ['El motivo no puede superar los 255 caracteres.']]);
        }

        return DB::connection('tenant')->transaction(function () use ($lineId, $clean, $userId, $scope) {
            [$statement, , $line] = $this->lockContextForLine($lineId, $scope);
            $this->assertDraft($statement);
            $this->assertLineStatus($line, BankStatementLine::STATUS_UNMATCHED);

            $line->status = BankStatementLine::STATUS_IGNORED;
            $line->ignore_reason = $clean;
            $line->ignored_by = $userId;
            $line->save();

            return $line;
        });
    }

    public function unignoreLine(int $lineId, int $userId, array $scope): BankStatementLine
    {
        $this->assertUser($userId);

        return DB::connection('tenant')->transaction(function () use ($lineId, $scope) {
            [$statement, , $line] = $this->lockContextForLine($lineId, $scope);
            $this->assertDraft($statement);
            $this->assertLineStatus($line, BankStatementLine::STATUS_IGNORED);

            $line->status = BankStatementLine::STATUS_UNMATCHED;
            $line->ignore_reason = null;
            $line->ignored_by = null;
            $line->save();

            return $line;
        });
    }

    /**
     * Turns an unmatched line into a TreasuryMovement through MovementService (so every rule, guard and BN account
     * restriction applies) and matches it to the line in the same transaction.
     *
     * @param array{kind: string, treasury_concept_id?: ?int, description?: ?string, establishment_id?: ?int, counterparty_name?: ?string} $payload
     *
     * @return array{line: BankStatementLine, movement: TreasuryMovement}
     */
    public function createMovementFromLine(int $lineId, array $payload, int $userId, array $scope): array
    {
        $this->assertUser($userId);

        $kind = (string) ($payload['kind'] ?? '');
        if (!array_key_exists($kind, self::LINE_MOVEMENT_KINDS)) {
            throw ValidationException::withMessages(['kind' => ['El tipo de movimiento no es válido para una línea del extracto.']]);
        }

        return DB::connection('tenant')->transaction(function () use ($lineId, $payload, $kind, $userId, $scope) {
            [$statement, $account, $line] = $this->lockContextForLine($lineId, $scope);
            $this->assertDraft($statement);
            $this->assertLineStatus($line, BankStatementLine::STATUS_UNMATCHED);

            $amount = (float) $line->amount;
            $requiredSign = self::LINE_MOVEMENT_KINDS[$kind];
            if ($requiredSign === 1 && $amount <= 0) {
                throw ValidationException::withMessages(['kind' => ['Este tipo de movimiento corresponde a un abono; la línea es un cargo.']]);
            }
            if ($requiredSign === -1 && $amount >= 0) {
                throw ValidationException::withMessages(['kind' => ['Este tipo de movimiento corresponde a un cargo; la línea es un abono.']]);
            }

            $direction = $amount > 0 ? TreasuryMovement::DIRECTION_INFLOW : TreasuryMovement::DIRECTION_OUTFLOW;
            $conceptId = $this->resolveConceptId($kind, $direction, $payload['treasury_concept_id'] ?? null);

            $description = trim((string) ($payload['description'] ?? ''));
            if ($description === '') {
                $description = (string) $line->description;
            }

            $result = $this->movementService->createManual([
                'kind' => $kind,
                'direction' => $direction,
                'date' => $line->date->format('Y-m-d'),
                'amount' => abs($amount),
                'destination_type' => 'bank_account',
                'destination_id' => (int) $account->id,
                'establishment_id' => !empty($payload['establishment_id']) ? (int) $payload['establishment_id'] : null,
                'treasury_concept_id' => $conceptId,
                'description' => mb_substr($description, 0, 500),
                'operation_number' => $line->reference !== null ? mb_substr((string) $line->reference, 0, 60) : null, // column is string(60)
                'counterparty_name' => $payload['counterparty_name'] ?? null,
                'include_itf' => false,
            ], $userId, ['itf']);

            /** @var TreasuryMovement $movement */
            $movement = $result['movement'];

            $item = $this->resolveMovementItem((int) $movement->id, $account);
            if ($item === null) {
                throw ValidationException::withMessages(['kind' => ['No se pudo emparejar el movimiento creado con la línea.']]);
            }
            $this->attachItems($line, [$item], $userId);

            return ['line' => $line->fresh(), 'movement' => $movement->fresh()];
        });
    }

    /**
     * Establishment to register a movement created from a statement line under.
     * - Non-admins always use their own establishment.
     * - Admins: the requested one; otherwise the establishment of the statement's ACCOUNT (so the movement never
     *   mismatches the account); only an account without establishment falls back to the user's own and then to
     *   $fallbackProvider() (e.g. the first establishment).
     */
    public function resolveLineEstablishmentId(int $lineId, ?int $requested, array $scope, ?callable $fallbackProvider = null): int
    {
        if (!$this->isAdminScope($scope)) {
            return (int) ($scope['establishment_id'] ?? 0);
        }

        $statementId = (int) BankStatementLine::query()->whereKey($lineId)->value('bank_statement_id');
        if ($statementId <= 0) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(BankStatementLine::class, [$lineId]);
        }
        $accountId = (int) BankStatement::query()->whereKey($statementId)->value('bank_account_id');
        $accountEstablishment = (int) BankAccount::query()->whereKey($accountId)->value('establishment_id');

        if (!empty($requested)) {
            return (int) $requested;
        }
        if ($accountEstablishment > 0) {
            return $accountEstablishment;
        }
        if ((int) ($scope['establishment_id'] ?? 0) > 0) {
            return (int) $scope['establishment_id'];
        }

        return $fallbackProvider ? (int) $fallbackProvider() : 0;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Summary, close, reopen
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Live reconciliation summary of a statement (a closed statement returns its stored snapshot).
     */
    public function summary(int $statementId, array $scope): array
    {
        $statement = BankStatement::query()->whereKey($statementId)->firstOrFail();
        $this->assertAccountAccess((int) $statement->bank_account_id, $scope);

        if ($statement->isClosed() && !empty($statement->snapshot)) {
            // The stored snapshot is immutable; only the stale flag is live (matches re-verified against today's data).
            $stale = $this->staleMatches($statement);

            return array_merge($statement->snapshot, $this->staleSummary($stale));
        }

        return $this->computeReconciliation($statement);
    }

    /**
     * Re-verifies every active match of a statement against current data: the system item must still exist, be active
     * and belong to the account, and its amount and date must equal what was matched (the stored match values).
     * Finance payments matched in a CLOSED statement are protected from delete/amount/date/destination changes by
     * ClosedReconciliationGuard, so a closed reconciliation only goes stale through paths the guard cannot block
     * (e.g. voiding a whole document through SUNAT, direct database edits).
     *
     * @return array<int, array{line_id: int, line_no: int, type: string, id: int, reason: string, expected_amount: float, current_amount: ?float, expected_date: ?string, current_date: ?string}>
     */
    public function staleMatches(BankStatement $statement): array
    {
        $account = BankAccount::query()->whereKey($statement->bank_account_id)->first();
        if (!$account) {
            return [];
        }

        $lines = BankStatementLine::query()->where('bank_statement_id', $statement->id)->pluck('line_no', 'id')->all();
        if (empty($lines)) {
            return [];
        }

        $matches = BankStatementLineMatch::query()
            ->whereIn('bank_statement_line_id', array_keys($lines))
            ->whereNull('released_at')
            ->orderBy('id')
            ->get();
        if ($matches->isEmpty()) {
            return [];
        }

        $movementIds = $matches->where('matchable_type', TreasuryMovement::class)->pluck('matchable_id')->map(function ($id) {
            return (int) $id;
        })->all();
        $paymentIds = $matches->where('matchable_type', GlobalPayment::class)->pluck('matchable_id')->map(function ($id) {
            return (int) $id;
        })->all();

        $current = [];
        if (!empty($movementIds)) {
            foreach (TreasuryMovement::query()->whereIn('id', $movementIds)->get() as $movement) {
                if ($movement->state === TreasuryMovement::STATE_ACTIVE
                    && $movement->destination_type === BankAccount::class
                    && (int) $movement->destination_id === (int) $account->id) {
                    $item = $this->presentMovementItem($movement);
                    $current[$item['key']] = $item;
                }
            }
        }
        if (!empty($paymentIds)) {
            foreach ($this->queryGlobalPayments((int) $account->id, (string) $account->currency_type_id, null, null, $paymentIds) as $item) {
                $current[$item['key']] = $item;
            }
        }

        $stale = [];
        foreach ($matches as $match) {
            $key = $match->matchable_type . ':' . $match->matchable_id;
            $item = $current[$key] ?? null;
            $expectedDate = $match->item_date ? $match->item_date->format('Y-m-d') : null;
            $expectedAmount = Money::round((float) $match->amount);

            $reason = null;
            if ($item === null) {
                $reason = 'missing';
            } elseif ($this->cents($item['amount']) !== $this->cents($expectedAmount)) {
                $reason = 'amount_changed';
            } elseif ($expectedDate !== null && $item['date'] !== $expectedDate) {
                $reason = 'date_changed';
            }

            if ($reason !== null) {
                $stale[] = [
                    'line_id' => (int) $match->bank_statement_line_id,
                    'line_no' => (int) ($lines[$match->bank_statement_line_id] ?? 0),
                    'type' => $match->matchable_type === TreasuryMovement::class ? 'movement' : 'global_payment',
                    'id' => (int) $match->matchable_id,
                    'reason' => $reason,
                    'expected_amount' => $expectedAmount,
                    'current_amount' => $item !== null ? $item['amount'] : null,
                    'expected_date' => $expectedDate,
                    'current_date' => $item !== null ? $item['date'] : null,
                ];
            }
        }

        return $stale;
    }

    /**
     * @param array<int, array> $stale
     */
    private function staleSummary(array $stale): array
    {
        return [
            'stale' => !empty($stale),
            'stale_count' => count($stale),
            'stale_matches' => array_slice($stale, 0, 50),
        ];
    }

    public function closeStatement(int $statementId, int $userId, array $scope): BankStatement
    {
        $this->assertUser($userId);

        return DB::connection('tenant')->transaction(function () use ($statementId, $userId, $scope) {
            [$statement, $account] = $this->lockContext($statementId, $scope);
            $this->assertDraft($statement);

            $lines = BankStatementLine::query()
                ->where('bank_statement_id', $statement->id)
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();

            $pending = $lines->where('status', BankStatementLine::STATUS_UNMATCHED);
            if ($pending->count() > 0) {
                throw ValidationException::withMessages([
                    'statement' => [
                        'No se puede cerrar la conciliación: quedan ' . $pending->count() . ' línea(s) sin emparejar ni ignorar (importe neto '
                        . Money::format((float) $pending->sum('amount')) . ').',
                    ],
                ]);
            }

            $matchedLineIds = $lines->where('status', BankStatementLine::STATUS_MATCHED)->pluck('id')->all();
            $matches = empty($matchedLineIds)
                ? collect()
                : BankStatementLineMatch::query()
                    ->whereIn('bank_statement_line_id', $matchedLineIds)
                    ->whereNull('released_at')
                    ->orderBy('id')
                    ->get();

            // Every matched system item must still exist and be valid (e.g. a payment deleted after matching).
            $movementIds = [];
            foreach ($matches as $match) {
                if ($match->matchable_type === TreasuryMovement::class) {
                    $item = $this->resolveMovementItem((int) $match->matchable_id, $account, true);
                    if ($item !== null) {
                        $movementIds[] = (int) $match->matchable_id;
                    }
                } else {
                    $item = $this->resolveGlobalPaymentItem((int) $match->matchable_id, $account, true);
                }
                if ($item === null) {
                    throw ValidationException::withMessages([
                        'statement' => ['Un movimiento emparejado ya no existe o fue anulado. Desvincule la línea correspondiente antes de cerrar.'],
                    ]);
                }
                if ($this->cents($item['amount']) !== $this->cents((float) $match->amount)
                    || ($match->item_date && $item['date'] !== $match->item_date->format('Y-m-d'))) {
                    throw ValidationException::withMessages([
                        'statement' => ['Un movimiento emparejado fue modificado (importe o fecha) después de emparejarlo. Desvincule la línea correspondiente antes de cerrar.'],
                    ]);
                }
            }

            $reconciliation = $this->computeReconciliation($statement);
            if (!$reconciliation['balanced']) {
                throw ValidationException::withMessages([
                    'statement' => [$this->differenceMessage($reconciliation)],
                ]);
            }

            $now = now();
            if (!empty($movementIds)) {
                TreasuryMovement::query()->whereIn('id', $movementIds)->update(['reconciled_at' => $now]);
            }

            $reconciliation['closed_at'] = $now->format('Y-m-d H:i:s');
            $statement->status = BankStatement::STATUS_CLOSED;
            $statement->closed_at = $now;
            $statement->closed_by = $userId;
            $statement->snapshot = $reconciliation;
            $statement->save();

            return $statement;
        });
    }

    /**
     * Reopens a closed statement (admin only). Matched movements stop being "reconciled" so they can be edited again.
     */
    public function reopenStatement(int $statementId, int $userId, array $scope): BankStatement
    {
        $this->assertUser($userId);

        if (!$this->isAdminScope($scope)) {
            throw new AuthorizationException('Solo un administrador puede reabrir una conciliación cerrada.');
        }

        return DB::connection('tenant')->transaction(function () use ($statementId, $scope) {
            [$statement] = $this->lockContext($statementId, $scope);

            if (!$statement->isClosed()) {
                throw ValidationException::withMessages(['statement' => ['La conciliación no está cerrada.']]);
            }

            $later = BankStatement::query()
                ->where('bank_account_id', $statement->bank_account_id)
                ->where('status', BankStatement::STATUS_CLOSED)
                ->whereDate('period_to', '>', $statement->period_to->format('Y-m-d'))
                ->exists();
            if ($later) {
                throw ValidationException::withMessages([
                    'statement' => ['Existe una conciliación posterior cerrada para esta cuenta. Reábrala primero.'],
                ]);
            }

            $lineIds = BankStatementLine::query()->where('bank_statement_id', $statement->id)->pluck('id')->all();
            if (!empty($lineIds)) {
                $movementIds = BankStatementLineMatch::query()
                    ->whereIn('bank_statement_line_id', $lineIds)
                    ->whereNull('released_at')
                    ->where('matchable_type', TreasuryMovement::class)
                    ->pluck('matchable_id')
                    ->all();
                if (!empty($movementIds)) {
                    TreasuryMovement::query()->whereIn('id', $movementIds)->orderBy('id')->lockForUpdate()->get();
                    TreasuryMovement::query()->whereIn('id', $movementIds)->update(['reconciled_at' => null]);
                }
            }

            $statement->status = BankStatement::STATUS_DRAFT;
            $statement->closed_at = null;
            $statement->closed_by = null;
            $statement->snapshot = null;
            $statement->save();

            return $statement;
        });
    }

    /**
     * Computes the reconciliation of a statement against the books (see class docblock for the identity).
     */
    public function computeReconciliation(BankStatement $statement): array
    {
        $account = BankAccount::query()->whereKey($statement->bank_account_id)->firstOrFail();
        $accountId = (int) $account->id;
        $currency = (string) $account->currency_type_id;
        $periodFrom = $statement->period_from->format('Y-m-d');
        $periodTo = $statement->period_to->format('Y-m-d');

        $system = $this->balanceService->getBankAccountBalanceAsOf($accountId, $periodTo);

        // Cumulative scope: every statement of the account up to this one (bank balances are cumulative).
        $scopeStatements = BankStatement::query()
            ->where('bank_account_id', $accountId)
            ->whereDate('period_to', '<=', $periodTo)
            ->get(['id', 'period_from', 'period_to']);
        $scopeIds = $scopeStatements->pluck('id')->all();
        $anchorFrom = $scopeStatements->min(function ($s) {
            return $s->period_from->format('Y-m-d');
        }) ?: $periodFrom;

        $scopeLineIds = BankStatementLine::query()->whereIn('bank_statement_id', $scopeIds)->pluck('id')->all();

        $ignoredCumulative = 0.0;
        if (!empty($scopeIds)) {
            $ignoredCumulative = (float) BankStatementLine::query()
                ->whereIn('bank_statement_id', $scopeIds)
                ->where('status', BankStatementLine::STATUS_IGNORED)
                ->sum('amount');
        }

        $activeMatches = empty($scopeLineIds)
            ? collect()
            : BankStatementLineMatch::query()->whereIn('bank_statement_line_id', $scopeLineIds)->whereNull('released_at')->get();

        $matchedKeys = [];
        $ahead = 0.0;
        foreach ($activeMatches as $match) {
            $matchedKeys[$match->matchable_type . ':' . $match->matchable_id] = true;
            if ($match->item_date && $match->item_date->format('Y-m-d') > $periodTo) {
                $ahead += (float) $match->amount;
            }
        }

        // In-transit: system items from the first statement start up to the period end that no line explains.
        $items = $this->loadSystemItems($accountId, $currency, $anchorFrom, $periodTo);
        $inTransit = [];
        $inTransitNet = 0.0;
        $rangeNet = 0.0;
        $convertedCount = 0;
        foreach ($items as $item) {
            $rangeNet += $item['amount'];
            if (!empty($item['converted'])) {
                $convertedCount++;
            }
            if (isset($matchedKeys[$item['key']])) {
                continue;
            }
            $inTransit[] = $item;
            $inTransitNet += $item['amount'];
        }

        // Book adjustments: credit-note deductions that the balance applies but no bank line can ever match. Only the
        // ones that appeared since the first statement started (earlier ones are inside its opening balance).
        $beforeAnchor = (new \DateTimeImmutable($anchorFrom))->modify('-1 day')->format('Y-m-d');
        $knownBefore = [];
        foreach ($this->balanceService->getBankAccountCreditNoteAdjustments($accountId, $beforeAnchor) as $adj) {
            $knownBefore[$adj['key']] = true;
        }
        $adjustments = [];
        $adjustmentsNet = 0.0;
        foreach ($this->balanceService->getBankAccountCreditNoteAdjustments($accountId, $periodTo) as $adj) {
            if (isset($knownBefore[$adj['key']])) {
                continue;
            }
            $adjustments[] = [
                'type' => 'credit_note_adjustment',
                'matchable' => false,
                'id' => $adj['credit_note_document_id'],
                'document_id' => $adj['document_id'],
                'date' => $adj['date'],
                'amount' => $adj['amount'],
                'currency_type_id' => $adj['currency_type_id'],
                'label' => 'Nota de crédito',
                'description' => 'NC ' . $adj['number'] . ' sobre ' . $adj['document_number'],
            ];
            $adjustmentsNet += $adj['amount'];
        }

        // Rounding drift (W8): books move by (balance as of period_to - balance before the first statement). What the
        // listed items and adjustments do not explain is rounding of cross-currency items, accepted only up to the
        // configured tolerance per converted item; anything bigger stays visible as a real difference.
        $systemBefore = $this->balanceService->getBankAccountBalanceAsOf($accountId, $beforeAnchor);
        $drift = Money::round(((float) $system['balance'] - (float) $systemBefore['balance']) - $adjustmentsNet - $rangeNet);
        $tolerance = max(0.0, (float) $this->configValue('treasury.reconciliation.rounding_tolerance', 0.01)) * $convertedCount;
        $driftApplied = $convertedCount > 0 && $this->cents($drift) !== 0 && abs($this->cents($drift)) <= $this->cents($tolerance);
        $itemsNet = $inTransitNet;
        if ($driftApplied) {
            $inTransitNet += $drift;
        }

        // This statement's own figures.
        $lines = BankStatementLine::query()->where('bank_statement_id', $statement->id)->get(['status', 'amount']);
        $byStatus = [];
        foreach ([BankStatementLine::STATUS_UNMATCHED, BankStatementLine::STATUS_MATCHED, BankStatementLine::STATUS_IGNORED] as $status) {
            $subset = $lines->where('status', $status);
            $byStatus[$status] = ['count' => $subset->count(), 'net' => Money::round((float) $subset->sum('amount'))];
        }

        $closing = (float) $statement->closing_balance;
        $adjustedBank = $closing - $ignoredCumulative - $ahead + $inTransitNet + $adjustmentsNet;
        $difference = Money::round($adjustedBank - (float) $system['balance']);

        $limit = (int) $this->configValue('treasury.reconciliation.snapshot_item_limit', 200);
        $listed = array_map(function ($item) {
            unset($item['key']);

            return $item;
        }, array_slice($inTransit, 0, $limit));

        $stale = $this->staleMatches($statement);

        return [
            'currency_type_id' => $currency,
            'period_from' => $periodFrom,
            'period_to' => $periodTo,
            'bank_closing_balance' => Money::round($closing),
            'system_balance' => Money::round((float) $system['balance']),
            'system_balance_as_of' => $periodTo,
            'matched' => $byStatus[BankStatementLine::STATUS_MATCHED],
            'ignored' => [
                'count' => $byStatus[BankStatementLine::STATUS_IGNORED]['count'],
                'net' => $byStatus[BankStatementLine::STATUS_IGNORED]['net'],
                'net_cumulative' => Money::round($ignoredCumulative),
            ],
            'unmatched' => $byStatus[BankStatementLine::STATUS_UNMATCHED],
            'ahead_net' => Money::round($ahead),
            'in_transit' => [
                'count' => count($inTransit),
                'net' => Money::round($inTransitNet),
                'items' => $listed,
                'truncated' => count($inTransit) > $limit,
                'from' => $anchorFrom,
                'items_net' => Money::round($itemsNet),
                'rounding_drift' => $driftApplied ? $drift : 0.0,
            ],
            'book_adjustments' => [
                'label' => 'Ajustes contables (notas de crédito)',
                'count' => count($adjustments),
                'net' => Money::round($adjustmentsNet),
                'items' => array_slice($adjustments, 0, $limit),
                'truncated' => count($adjustments) > $limit,
            ],
            'rounding_tolerance' => Money::round($tolerance),
            'imported_unbalanced' => (bool) $statement->imported_unbalanced,
            'unbalanced_gap' => $statement->unbalanced_gap !== null ? Money::round((float) $statement->unbalanced_gap) : null,
            'adjusted_bank_balance' => Money::round($adjustedBank),
            'difference' => $difference,
            'balanced' => $this->cents($difference) === 0,
            'computed_at' => date('Y-m-d H:i:s'),
        ] + $this->staleSummary($stale);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Mappings
    // ------------------------------------------------------------------------------------------------------------

    public function listMappings(array $scope, ?int $bankAccountId = null): array
    {
        $query = BankStatementMapping::query()->orderBy('name');

        if (!$this->isAdminScope($scope)) {
            // Non-admins only see mappings that are global (no account) or bound to an account they can operate.
            $establishmentId = (int) ($scope['establishment_id'] ?? 0);
            $allowedAccounts = $establishmentId > 0
                ? BankAccount::query()->where(function ($q) use ($establishmentId) {
                    $q->whereNull('establishment_id')->orWhere('establishment_id', $establishmentId);
                })->pluck('id')->all()
                : [];
            $query->where(function ($q) use ($allowedAccounts) {
                $q->whereNull('bank_account_id')->orWhereIn('bank_account_id', $allowedAccounts ?: [-1]);
            });
        }
        if ($bankAccountId) {
            $query->where(function ($q) use ($bankAccountId) {
                $q->whereNull('bank_account_id')->orWhere('bank_account_id', $bankAccountId);
            });
        }

        return $query->get()->map(function (BankStatementMapping $m) {
            return [
                'id' => (int) $m->id,
                'name' => $m->name,
                'bank_id' => $m->bank_id,
                'bank_account_id' => $m->bank_account_id,
                'config' => $m->config,
            ];
        })->values()->all();
    }

    public function saveMapping(array $payload, array $config, int $userId, array $scope): BankStatementMapping
    {
        $this->assertUser($userId);

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => ['El nombre del mapeo es obligatorio (máximo 100 caracteres).']]);
        }

        $accountId = !empty($payload['bank_account_id']) ? (int) $payload['bank_account_id'] : null;
        if ($accountId !== null) {
            $this->assertAccountAccess($accountId, $scope);
        } elseif (!$this->isAdminScope($scope) && (int) ($scope['establishment_id'] ?? 0) <= 0) {
            throw new AuthorizationException('No tiene permiso para guardar mapeos.');
        }

        $bankId = !empty($payload['bank_id']) ? (int) $payload['bank_id'] : null;
        if ($accountId !== null) {
            $bankId = (int) BankAccount::query()->whereKey($accountId)->value('bank_id') ?: $bankId;
        }

        $id = !empty($payload['id']) ? (int) $payload['id'] : null;

        return DB::connection('tenant')->transaction(function () use ($id, $name, $bankId, $accountId, $config, $userId, $scope) {
            if ($id !== null) {
                $mapping = BankStatementMapping::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->assertMappingAccess($mapping, $userId, $scope);
                $mapping->fill(['name' => $name, 'bank_id' => $bankId, 'bank_account_id' => $accountId, 'config' => $config])->save();

                return $mapping;
            }

            return BankStatementMapping::create([
                'name' => $name,
                'bank_id' => $bankId,
                'bank_account_id' => $accountId,
                'config' => $config,
                'created_by' => $userId,
            ]);
        });
    }

    public function deleteMapping(int $id, int $userId, array $scope): void
    {
        $mapping = BankStatementMapping::query()->whereKey($id)->firstOrFail();
        $this->assertMappingAccess($mapping, $userId, $scope);
        $mapping->delete();
    }

    private function assertMappingAccess(BankStatementMapping $mapping, int $userId, array $scope): void
    {
        if ($this->isAdminScope($scope)) {
            return;
        }
        if ((int) $mapping->created_by !== $userId) {
            throw new AuthorizationException('Solo el usuario que creó el mapeo o un administrador puede modificarlo.');
        }
        if (!empty($mapping->bank_account_id)) {
            $this->assertAccountAccess((int) $mapping->bank_account_id, $scope);
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Internals: locking
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Locks bank account -> statement and checks access. Must run inside a transaction.
     *
     * @return array{0: BankStatement, 1: BankAccount}
     */
    private function lockContext(int $statementId, array $scope): array
    {
        $accountId = (int) BankStatement::query()->whereKey($statementId)->value('bank_account_id');
        if ($accountId <= 0) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(BankStatement::class, [$statementId]);
        }

        $account = BankAccount::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
        $statement = BankStatement::query()->whereKey($statementId)->lockForUpdate()->firstOrFail();
        $this->assertAccountAccess($accountId, $scope);

        return [$statement, $account];
    }

    /**
     * @return array{0: BankStatement, 1: BankAccount, 2: BankStatementLine}
     */
    private function lockContextForLine(int $lineId, array $scope): array
    {
        $statementId = (int) BankStatementLine::query()->whereKey($lineId)->value('bank_statement_id');
        if ($statementId <= 0) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(BankStatementLine::class, [$lineId]);
        }

        [$statement, $account] = $this->lockContext($statementId, $scope);
        $line = BankStatementLine::query()->whereKey($lineId)->lockForUpdate()->firstOrFail();

        return [$statement, $account, $line];
    }

    private function assertDraft(BankStatement $statement): void
    {
        if ($statement->isClosed()) {
            throw ValidationException::withMessages([
                'statement' => ['La conciliación está cerrada. Reábrala (administrador) para modificarla.'],
            ]);
        }
    }

    private function assertLineStatus(BankStatementLine $line, string $expected): void
    {
        if ($line->status === $expected) {
            return;
        }

        $messages = [
            BankStatementLine::STATUS_UNMATCHED => 'La línea ya está emparejada o ignorada.',
            BankStatementLine::STATUS_MATCHED => 'La línea no está emparejada.',
            BankStatementLine::STATUS_IGNORED => 'La línea no está ignorada.',
        ];

        throw ValidationException::withMessages(['line' => [$messages[$expected] ?? 'Estado de línea no válido.']]);
    }

    private function assertUser(int $userId): void
    {
        if ($userId <= 0) {
            throw ValidationException::withMessages(['user_id' => ['El usuario que realiza la operación es obligatorio.']]);
        }
    }

    // ------------------------------------------------------------------------------------------------------------
    // Internals: matches
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Inserts the pivot rows and flags the line as matched. The signed sum of the items must equal the line amount.
     *
     * @param array<int, array> $items resolved items (see loadSystemItems)
     */
    private function attachItems(BankStatementLine $line, array $items, int $userId): void
    {
        $sumCents = 0;
        foreach ($items as $item) {
            $sumCents += $this->cents($item['amount']);
        }
        $lineCents = $this->cents((float) $line->amount);

        if ($sumCents !== $lineCents) {
            throw ValidationException::withMessages([
                'items' => [
                    'La suma de los movimientos seleccionados (' . Money::format($sumCents / 100) . ') no coincide con el importe de la línea ('
                    . Money::format($lineCents / 100) . ').',
                ],
            ]);
        }

        foreach ($items as $item) {
            $class = $item['type'] === 'movement' ? TreasuryMovement::class : GlobalPayment::class;
            $key = BankStatementLineMatch::keyFor($class, (int) $item['id']);

            if (BankStatementLineMatch::query()->where('active_match_key', $key)->exists()) {
                throw ValidationException::withMessages([
                    'items' => ['Uno de los movimientos ya está emparejado con otra línea de extracto.'],
                ]);
            }

            try {
                BankStatementLineMatch::create([
                    'bank_statement_line_id' => $line->id,
                    'matchable_type' => $class,
                    'matchable_id' => (int) $item['id'],
                    'amount' => Money::round($item['amount']),
                    'item_date' => $item['date'],
                    'active_match_key' => $key,
                    'matched_by' => $userId,
                ]);
            } catch (QueryException $e) {
                if (DatabaseErrors::isDuplicateKey($e)) {
                    throw ValidationException::withMessages([
                        'items' => ['Uno de los movimientos ya está emparejado con otra línea de extracto.'],
                    ]);
                }
                throw $e;
            }

            if ($class === TreasuryMovement::class) {
                TreasuryMovement::query()->whereKey((int) $item['id'])->update(['bank_statement_line_id' => $line->id]);
            }
        }

        $line->status = BankStatementLine::STATUS_MATCHED;
        $line->save();
    }

    /**
     * @param array<int, int> $lineIds
     */
    private function releaseMatches(array $lineIds, int $userId): void
    {
        $matches = BankStatementLineMatch::query()
            ->whereIn('bank_statement_line_id', $lineIds)
            ->whereNull('released_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $movementIds = [];
        foreach ($matches as $match) {
            if ($match->matchable_type === TreasuryMovement::class) {
                $movementIds[] = (int) $match->matchable_id;
            }
            $match->active_match_key = null;
            $match->released_at = now();
            $match->released_by = $userId;
            $match->save();
        }

        if (!empty($movementIds)) {
            TreasuryMovement::query()->whereIn('id', $movementIds)->update(['bank_statement_line_id' => null]);
        }
    }

    /**
     * @return array<string, bool> keys of system items with an active match on any statement of the account
     */
    private function activeMatchKeys(int $accountId): array
    {
        $rows = DB::connection('tenant')
            ->table('bank_statement_line_matches as m')
            ->join('bank_statement_lines as l', 'l.id', '=', 'm.bank_statement_line_id')
            ->join('bank_statements as s', 's.id', '=', 'l.bank_statement_id')
            ->where('s.bank_account_id', $accountId)
            ->whereNull('m.released_at')
            ->get(['m.matchable_type', 'm.matchable_id']);

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row->matchable_type . ':' . $row->matchable_id] = true;
        }

        return $keys;
    }

    private function resolveConceptId(string $kind, int $direction, $explicitId): int
    {
        if (!empty($explicitId)) {
            return (int) $explicitId;
        }

        if (isset(self::DEFAULT_CONCEPTS[$kind])) {
            [$type, $description] = self::DEFAULT_CONCEPTS[$kind];
        } elseif ($kind === 'adjustment') {
            [$type, $description] = $direction === TreasuryMovement::DIRECTION_INFLOW
                ? ['income', 'Otros ingresos']
                : ['expense', 'Otros egresos'];
        } else {
            throw ValidationException::withMessages(['treasury_concept_id' => ['Seleccione el concepto del movimiento.']]);
        }

        $id = TreasuryConcept::query()->where('type', $type)->where('description', $description)->where('active', true)->value('id');
        if (!$id) {
            throw ValidationException::withMessages([
                'treasury_concept_id' => ['Seleccione el concepto del movimiento (no se encontró el concepto predeterminado).'],
            ]);
        }

        return (int) $id;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Internals: system items (movements + global payments)
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Normalized system items of a bank account within an optional date range.
     *
     * Item shape: key, type (movement|global_payment), id, date (Y-m-d), amount (SIGNED, account currency), converted
     * (true when the payment currency differs from the account currency), currency_type_id, label, description,
     * reference.
     *
     * @return array<int, array>
     */
    private function loadSystemItems(int $accountId, string $currency, ?string $from, ?string $to): array
    {
        $items = [];

        $movements = TreasuryMovement::query()
            ->active()
            ->where('destination_type', BankAccount::class)
            ->where('destination_id', $accountId);
        if ($from !== null) {
            $movements->whereDate('date', '>=', $from);
        }
        if ($to !== null) {
            $movements->whereDate('date', '<=', $to);
        }
        foreach ($movements->get() as $movement) {
            $items[] = $this->presentMovementItem($movement);
        }

        foreach ($this->queryGlobalPayments($accountId, $currency, $from, $to, null) as $item) {
            $items[] = $item;
        }

        usort($items, function ($a, $b) {
            return [$a['date'], $a['key']] <=> [$b['date'], $b['key']];
        });

        return $items;
    }

    private function presentMovementItem(TreasuryMovement $movement): array
    {
        return [
            'key' => TreasuryMovement::class . ':' . $movement->id,
            'type' => 'movement',
            'id' => (int) $movement->id,
            'date' => $movement->date->format('Y-m-d'),
            'amount' => Money::round((float) $movement->direction * (float) $movement->amount),
            'converted' => false,
            'currency_type_id' => (string) $movement->currency_type_id,
            'label' => MovementService::KIND_LABELS[$movement->kind] ?? $movement->kind,
            'description' => (string) $movement->description,
            'reference' => $movement->operation_number,
        ];
    }

    /**
     * Locks and resolves a movement as a candidate for the account (null when it cannot be matched).
     */
    private function resolveMovementItem(int $id, BankAccount $account, bool $allowMatched = false): ?array
    {
        $movement = TreasuryMovement::query()->whereKey($id)->lockForUpdate()->first();
        if (!$movement
            || $movement->state !== TreasuryMovement::STATE_ACTIVE
            || $movement->destination_type !== BankAccount::class
            || (int) $movement->destination_id !== (int) $account->id) {
            return null;
        }

        if (!$allowMatched) {
            if ($movement->reconciled_at !== null || $movement->bank_statement_line_id !== null) {
                return null;
            }
            $key = BankStatementLineMatch::keyFor(TreasuryMovement::class, $id);
            if (BankStatementLineMatch::query()->where('active_match_key', $key)->exists()) {
                return null;
            }
        }

        return $this->presentMovementItem($movement);
    }

    private function resolveGlobalPaymentItem(int $id, BankAccount $account, bool $allowMatched = false): ?array
    {
        // Lock the global payment row so a concurrent reconciliation of the same account cannot take it.
        DB::connection('tenant')->table('global_payments')->where('id', $id)->lockForUpdate()->first();

        $found = $this->queryGlobalPayments((int) $account->id, (string) $account->currency_type_id, null, null, [$id]);
        if (empty($found)) {
            return null;
        }

        if (!$allowMatched) {
            $key = BankStatementLineMatch::keyFor(GlobalPayment::class, $id);
            if (BankStatementLineMatch::query()->where('active_match_key', $key)->exists()) {
                return null;
            }
        }

        return $found[0];
    }

    /**
     * Global payments whose destination is the bank account, with the signed amount in the account currency.
     * Applies the same state filters as TreasuryBalanceService (accepted parent states, `changed = false`), so the
     * candidates add up to what the balance counts.
     *
     * @param array<int, int>|null $ids restrict to these global payment ids
     *
     * @return array<int, array>
     */
    private function queryGlobalPayments(int $accountId, string $currency, ?string $from, ?string $to, ?array $ids): array
    {
        $schema = DB::connection('tenant')->getSchemaBuilder();
        if (!$schema->hasTable('global_payments')) {
            return [];
        }

        $items = [];
        foreach (self::PAYMENT_SPECS as $spec) {
            // The real MariaDB table is the singular `income`; `incomes` only exists in legacy test fixtures.
            if ($spec['parent'] === 'income' && !$schema->hasTable('income') && $schema->hasTable('incomes')) {
                $spec['parent'] = 'incomes';
            }
            if (!$schema->hasTable($spec['pay']) || !$schema->hasTable($spec['parent'])) {
                continue;
            }

            $q = DB::connection('tenant')
                ->table('global_payments as gp')
                ->join($spec['pay'] . ' as p', 'p.id', '=', 'gp.payment_id')
                ->join($spec['parent'] . ' as par', 'par.id', '=', 'p.' . $spec['fk'])
                ->where('gp.destination_type', BankAccount::class)
                ->where('gp.destination_id', $accountId)
                ->where('gp.payment_type', $spec['class']);

            if ($spec['has_state']) {
                $q->whereIn('par.state_type_id', TreasuryBalanceService::ACCEPTED_STATE_TYPES);
            }
            if ($spec['has_changed']) {
                $q->where('par.changed', false);
            }
            if ($ids !== null) {
                $q->whereIn('gp.id', $ids);
            }
            if ($from !== null) {
                $q->where('p.date_of_payment', '>=', $from);
            }
            if ($to !== null) {
                $q->where('p.date_of_payment', '<=', $to);
            }

            $select = ['gp.id as gp_id', 'p.date_of_payment', 'p.payment', 'p.reference'];
            if ($spec['has_currency']) {
                $select[] = 'par.currency_type_id as par_currency';
                $select[] = 'par.exchange_rate_sale as par_rate';
            }
            foreach ($spec['number_cols'] as $col) {
                $select[] = 'par.' . $col . ' as par_' . $col;
            }

            foreach ($q->get($select) as $row) {
                $raw = (float) $row->payment;
                $paymentCurrency = $spec['has_currency'] ? (string) ($row->par_currency ?: 'PEN') : 'PEN';
                $rate = $spec['has_currency'] ? (float) ($row->par_rate ?: 1.0) : 1.0;

                $converted = $paymentCurrency !== $currency;
                $amount = $this->convert($raw, $paymentCurrency, $rate, $currency);

                $numberParts = [];
                foreach ($spec['number_cols'] as $col) {
                    $value = $row->{'par_' . $col} ?? null;
                    if ($value !== null && $value !== '') {
                        $numberParts[] = $value;
                    }
                }

                $items[] = [
                    'key' => GlobalPayment::class . ':' . $row->gp_id,
                    'type' => 'global_payment',
                    'id' => (int) $row->gp_id,
                    'date' => substr((string) $row->date_of_payment, 0, 10),
                    'amount' => Money::round($spec['sign'] * $amount),
                    'converted' => $converted,
                    'currency_type_id' => $currency,
                    'label' => $spec['label'],
                    'description' => trim($spec['label'] . (empty($numberParts) ? '' : ' ' . implode('-', $numberParts))),
                    'reference' => $row->reference !== null && $row->reference !== '' ? (string) $row->reference : null,
                ];
            }
        }

        return $items;
    }

    private function convert(float $amount, string $from, float $rate, string $to): float
    {
        if ($from === $to) {
            return $amount;
        }
        $rate = $rate > 0 ? $rate : 1.0;
        if ($from === 'USD' && $to === 'PEN') {
            return $amount * $rate;
        }
        if ($from === 'PEN' && $to === 'USD') {
            return $amount / $rate;
        }

        return $amount;
    }

    /**
     * @param \Illuminate\Support\Collection $matches
     *
     * @return array<string, array{label: ?string, description: ?string}>
     */
    private function describeMatchables(BankStatement $statement, $matches): array
    {
        $result = [];
        $movementIds = $matches->where('matchable_type', TreasuryMovement::class)->pluck('matchable_id')->all();
        $paymentIds = $matches->where('matchable_type', GlobalPayment::class)->pluck('matchable_id')->map(function ($id) {
            return (int) $id;
        })->all();

        if (!empty($movementIds)) {
            foreach (TreasuryMovement::query()->whereIn('id', $movementIds)->get() as $movement) {
                $item = $this->presentMovementItem($movement);
                $result[$item['key']] = ['label' => $item['label'], 'description' => $item['description']];
            }
        }

        if (!empty($paymentIds)) {
            $account = BankAccount::query()->whereKey($statement->bank_account_id)->first();
            if ($account) {
                foreach ($this->queryGlobalPayments((int) $account->id, (string) $account->currency_type_id, null, null, $paymentIds) as $item) {
                    $result[$item['key']] = ['label' => $item['label'], 'description' => $item['description']];
                }
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Internals: presentation and helpers
    // ------------------------------------------------------------------------------------------------------------

    private function presentStatement(BankStatement $s, ?array $counts, ?string $accountDescription): array
    {
        // Only closed reconciliations can go stale (a draft is re-validated when it is closed).
        $stale = $s->isClosed() && !empty($this->staleMatches($s));

        $counts = $counts ?? ['total' => 0, 'matched' => 0, 'ignored' => 0, 'unmatched' => 0];

        return [
            'id' => (int) $s->id,
            'bank_account_id' => (int) $s->bank_account_id,
            'account_description' => $accountDescription,
            'period_from' => $s->period_from->format('Y-m-d'),
            'period_to' => $s->period_to->format('Y-m-d'),
            'opening_balance' => Money::round((float) $s->opening_balance),
            'closing_balance' => Money::round((float) $s->closing_balance),
            'file_name' => $s->file_name,
            'status' => $s->status,
            'closed_at' => $s->closed_at ? $s->closed_at->format('Y-m-d H:i:s') : null,
            'lines' => $counts,
            'difference' => $s->isClosed() && is_array($s->snapshot) ? ($s->snapshot['difference'] ?? null) : null,
            'imported_unbalanced' => (bool) $s->imported_unbalanced,
            'unbalanced_gap' => $s->unbalanced_gap !== null ? Money::round((float) $s->unbalanced_gap) : null,
            'stale' => $stale,
        ];
    }

    /**
     * @param array<int, int> $statementIds
     *
     * @return array<int, array{total: int, matched: int, ignored: int, unmatched: int}>
     */
    private function lineCounts(array $statementIds): array
    {
        if (empty($statementIds)) {
            return [];
        }

        $rows = DB::connection('tenant')
            ->table('bank_statement_lines')
            ->whereIn('bank_statement_id', $statementIds)
            ->groupBy('bank_statement_id', 'status')
            ->get(['bank_statement_id', 'status', DB::raw('COUNT(*) as total')]);

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row->bank_statement_id;
            $result[$id] = $result[$id] ?? ['total' => 0, 'matched' => 0, 'ignored' => 0, 'unmatched' => 0];
            $result[$id][$row->status] = (int) $row->total;
            $result[$id]['total'] += (int) $row->total;
        }

        return $result;
    }

    /**
     * @param array<int, int> $accountIds
     *
     * @return array<int, string>
     */
    private function accountDescriptions(array $accountIds): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $result = [];
        foreach (BankAccount::with('bank')->whereIn('id', $accountIds)->get() as $account) {
            $bank = $account->bank ? $account->bank->description : 'Banco';
            $result[(int) $account->id] = "{$bank} - {$account->currency_type_id} - {$account->description}";
        }

        return $result;
    }

    /**
     * Restricts a query on an account column to the accounts a non-admin may see (own establishment or shared).
     */
    private function applyAccountScope($query, string $column, array $scope): void
    {
        if ($this->isAdminScope($scope)) {
            return;
        }

        $establishmentId = (int) ($scope['establishment_id'] ?? 0);
        if ($establishmentId <= 0) {
            $query->whereRaw('1 = 0');

            return;
        }

        $allowed = BankAccount::query()->where(function ($q) use ($establishmentId) {
            $q->whereNull('establishment_id')->orWhere('establishment_id', $establishmentId);
        })->pluck('id')->all();

        $query->whereIn($column, empty($allowed) ? [-1] : $allowed);
    }

    private function differenceMessage(array $reconciliation): string
    {
        return 'No se puede cerrar la conciliación: la diferencia es ' . Money::format($reconciliation['difference'])
            . ' (saldo según banco ajustado ' . Money::format($reconciliation['adjusted_bank_balance'])
            . ' frente a saldo según sistema ' . Money::format($reconciliation['system_balance'])
            . ' al ' . $reconciliation['system_balance_as_of']
            . '). Revise el saldo inicial del primer extracto, los movimientos pendientes de registrar y las líneas ignoradas.';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function candidateRange(BankStatement $statement, int $window): array
    {
        $from = (new \DateTimeImmutable($statement->period_from->format('Y-m-d')))->modify("-{$window} days")->format('Y-m-d');
        $to = (new \DateTimeImmutable($statement->period_to->format('Y-m-d')))->modify("+{$window} days")->format('Y-m-d');

        return [$from, $to];
    }

    private function windowDays(): int
    {
        return max(0, (int) $this->configValue('treasury.reconciliation.date_window_days', 3));
    }

    private function normalizeReference(string $text): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $text));
    }

    private function daysBetween(string $a, string $b): int
    {
        return (int) (new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->format('%r%a');
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function requireDate($value, string $field, string $label): string
    {
        $text = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw ValidationException::withMessages([$field => [$label . ' debe tener el formato AAAA-MM-DD.']]);
        }

        return $date->format('Y-m-d');
    }

    private function requireNumber($value, string $field, string $label): float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw ValidationException::withMessages([$field => [$label . ' debe ser un número válido.']]);
        }
        $number = Money::round((float) $value);
        if (abs($number) > Money::MAX_AMOUNT) {
            throw ValidationException::withMessages([$field => [$label . ' excede el máximo permitido.']]);
        }

        return $number;
    }

    private function configValue(string $key, $default)
    {
        try {
            return config($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
