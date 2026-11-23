<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\ExchangeRate;
use App\Models\Tenant\Purchase;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Pos\Models\CashTransaction;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use Modules\Treasury\Models\DetractionRecord;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\Money;

/**
 * Computes Real Cash Flow (historical inflows/outflows by concept and week/month) and
 * Projected Cash Flow (AR/AP installments, pending payable SPOT detractions, and optional deferred instruments).
 *
 * Never calls auth() internally; callers must always pass explicit $scope.
 */
class CashFlowService
{
    public const CREDIT_NOTE_ADJUSTMENT_LABEL = 'Ajustes por notas de crédito';

    public const MAX_WEEKLY_RANGE_DAYS = 731;
    public const MAX_MONTHLY_RANGE_DAYS = 1826;

    public const SPANISH_MONTHS = [
        1 => 'Enero',
        2 => 'Febrero',
        3 => 'Marzo',
        4 => 'Abril',
        5 => 'Mayo',
        6 => 'Junio',
        7 => 'Julio',
        8 => 'Agosto',
        9 => 'Septiembre',
        10 => 'Octubre',
        11 => 'Noviembre',
        12 => 'Diciembre',
    ];

    public const LEGACY_PAYMENT_CATEGORIES = [
        DocumentPayment::class => ['label' => 'Cobranzas de facturas y boletas', 'direction' => 1],
        SaleNotePayment::class => ['label' => 'Cobranzas de notas de venta', 'direction' => 1],
        QuotationPayment::class => ['label' => 'Cobranzas de cotizaciones', 'direction' => 1],
        ContractPayment::class => ['label' => 'Cobranzas de contratos', 'direction' => 1],
        IncomePayment::class => ['label' => 'Otros ingresos (Finanzas)', 'direction' => 1],
        CashTransaction::class => ['label' => 'Apertura e ingresos de caja POS', 'direction' => 1],
        TechnicalServicePayment::class => ['label' => 'Cobranzas de servicio técnico', 'direction' => 1],
        PurchasePayment::class => ['label' => 'Pagos de compras', 'direction' => -1],
        ExpensePayment::class => ['label' => 'Pagos de gastos', 'direction' => -1],
    ];

    private $balanceService;
    private $receivablePayableService;

    /** @var array<string, bool> */
    private $tableExistsCache = [];

    public function __construct(
        TreasuryBalanceService $balanceService,
        ReceivablePayableService $receivablePayableService
    ) {
        $this->balanceService = $balanceService;
        $this->receivablePayableService = $receivablePayableService;
    }

    /**
     * Historical Real Cash Flow grouped by concept/category and weekly or monthly period buckets.
     */
    public function getRealCashFlow(array $filters, array $scope): array
    {
        $this->tableExistsCache = [];
        $this->columnExistsCache = [];
        $dateStart = !empty($filters['date_start'])
            ? (string) $filters['date_start']
            : Carbon::today()->startOfMonth()->format('Y-m-d');
        $dateEnd = !empty($filters['date_end'])
            ? (string) $filters['date_end']
            : Carbon::today()->format('Y-m-d');

        $granularity = isset($filters['granularity']) && in_array($filters['granularity'], ['week', 'month'], true)
            ? (string) $filters['granularity']
            : 'month';
        $this->assertValidDateRange($dateStart, $dateEnd, $granularity);

        $destinationType = isset($filters['destination_type']) && in_array($filters['destination_type'], ['all', 'cash', 'bank_account'], true)
            ? (string) $filters['destination_type']
            : 'all';

        [$isConsolidated, $filterCurrency, $reportCurrency] = $this->normalizeCurrencyFilter($filters['currency_type_id'] ?? null);
        $periods = $this->buildPeriods($dateStart, $dateEnd, $granularity);

        $resolvedScope = $this->resolveScope($filters, $scope);
        if (!$resolvedScope['allowed']) {
            return $this->emptyRealCashFlow($dateStart, $dateEnd, $granularity, $destinationType, $reportCurrency, $isConsolidated, null, $periods);
        }

        $effectiveEstId = $resolvedScope['establishment_id'];
        $includeBankAccounts = ($destinationType === 'all' || $destinationType === 'bank_account');
        $includeCash = ($destinationType === 'all' || $destinationType === 'cash') && ($isConsolidated || $filterCurrency === 'PEN');

        $bankAccounts = $includeBankAccounts
            ? $this->loadScopedBankAccounts($effectiveEstId, $filterCurrency)
            : [];
        $bankAccountIds = array_map(fn ($row) => (int) $row->id, $bankAccounts);
        $bankAccountCurrencyMap = [];
        foreach ($bankAccounts as $acc) {
            $bankAccountCurrencyMap[(int) $acc->id] = (string) $acc->currency_type_id;
        }

        $scopedCashIds = $includeCash
            ? $this->resolveScopedCashIds($resolvedScope['is_admin'], $effectiveEstId, $resolvedScope['user_id'])
            : [];

        $missingExchangeRate = false;
        $fallbackPurchaseRate = $this->resolveExchangeRatePurchase($dateEnd);
        $fallbackSaleRate = $this->resolveExchangeRateSale($dateEnd);

        // Opening balance as of day before date_start
        $dayBeforeStart = Carbon::parse($dateStart)->subDay()->format('Y-m-d');
        $openingRatePurchase = $this->resolveExchangeRatePurchase($dayBeforeStart);
        $openingBalance = 0.0;

        if ($includeBankAccounts && !empty($bankAccounts)) {
            foreach ($bankAccounts as $acc) {
                // Pass $effectiveEstId so shared accounts (establishment_id IS NULL) apply the exact same
                // establishment filter to opening treasury_movements as period treasury_movements.
                $asOf = $this->balanceService->getBankAccountBalanceAsOf((int) $acc->id, $dayBeforeStart, $effectiveEstId);
                $bal = (float) $asOf['balance'];
                $accCur = (string) $asOf['currency_type_id'];

                if ($isConsolidated) {
                    if ($accCur === 'PEN') {
                        $openingBalance += $bal;
                    } elseif ($accCur === 'USD') {
                        if ($openingRatePurchase > 0) {
                            $openingBalance += Money::round($bal * $openingRatePurchase);
                        } else {
                            $missingExchangeRate = true;
                        }
                    } else {
                        $missingExchangeRate = true;
                    }
                } elseif ($accCur === $reportCurrency) {
                    $openingBalance += $bal;
                }
            }
        }

        if ($includeCash && ($scopedCashIds === null || !empty($scopedCashIds))) {
            // Single scoped call (no per-cash loop): the balance service resolves the same cash scope
            // (establishment for admins; establishment + own user for non-admins) in one aggregate pass.
            $openingBalance += $this->balanceService->getCashBalanceAsOf(
                $dayBeforeStart,
                $effectiveEstId,
                null,
                true,
                $resolvedScope['is_admin'] ? null : $resolvedScope['user_id']
            );
        }
        $openingBalance = Money::round($openingBalance);

        // Accumulators: category => [periodKey => amount]
        $inflowsByCategory = [];
        $outflowsByCategory = [];
        $transfersByCategory = [];
        $separateInternalTransfers = ($destinationType === 'all');

        $addAmount = function (array &$bucket, string $category, string $periodKey, float $amount) use ($periods): void {
            if ($amount <= 0.0) {
                return;
            }
            if (!isset($bucket[$category])) {
                $bucket[$category] = [];
                foreach ($periods as $p) {
                    $bucket[$category][$p['key']] = 0.0;
                }
            }
            $bucket[$category][$periodKey] = Money::round(($bucket[$category][$periodKey] ?? 0.0) + $amount);
        };

        // 1. Active treasury_movements in [date_start, date_end]
        if ($this->hasTable('treasury_movements')) {
            $conceptsMap = $this->loadConceptsMap();

            $movQuery = DB::connection('tenant')
                ->table('treasury_movements')
                ->where('state', TreasuryMovement::STATE_ACTIVE)
                ->whereDate('date', '>=', $dateStart)
                ->whereDate('date', '<=', $dateEnd);

            if ($effectiveEstId !== null) {
                $movQuery->where('establishment_id', $effectiveEstId);
            }

            $movQuery->where(function ($q) use ($includeBankAccounts, $bankAccountIds, $includeCash, $scopedCashIds) {
                $added = false;
                if ($includeBankAccounts && !empty($bankAccountIds)) {
                    $q->where(function ($b) use ($bankAccountIds) {
                        $b->where('destination_type', BankAccount::class)
                            ->whereIn('destination_id', $bankAccountIds);
                    });
                    $added = true;
                }
                if ($includeCash && ($scopedCashIds === null || !empty($scopedCashIds))) {
                    $method = $added ? 'orWhere' : 'where';
                    $q->{$method}(function ($c) use ($scopedCashIds) {
                        $c->where('destination_type', Cash::class);
                        if ($scopedCashIds !== null) {
                            $c->whereIn('destination_id', $scopedCashIds);
                        }
                    });
                    $added = true;
                }
                if (!$added) {
                    $q->whereRaw('1 = 0');
                }
            });

            $movements = $movQuery->get([
                'id',
                'date',
                'kind',
                'direction',
                'currency_type_id',
                'amount',
                'amount_pen',
                'exchange_rate_used',
                'treasury_concept_id',
            ]);

            foreach ($movements as $mov) {
                $movDate = substr((string) $mov->date, 0, 10);
                $periodKey = $this->resolvePeriodKey($movDate, $periods);
                if ($periodKey === null) {
                    continue;
                }

                $movCurrency = strtoupper((string) ($mov->currency_type_id ?: 'PEN'));
                $amount = $this->convertMovementAmount(
                    (float) $mov->amount,
                    (float) $mov->amount_pen,
                    $movCurrency,
                    isset($mov->exchange_rate_used) ? (float) $mov->exchange_rate_used : null,
                    $isConsolidated,
                    $reportCurrency,
                    $fallbackPurchaseRate,
                    $missingExchangeRate
                );
                if ($amount <= 0.0) {
                    continue;
                }

                $kind = (string) $mov->kind;
                $direction = (int) $mov->direction;
                $isTransfer = in_array($kind, TreasuryMovement::TRANSFER_KINDS, true);

                if ($isTransfer && $separateInternalTransfers) {
                    $label = $kind === 'transfer_in' ? 'Transferencias recibidas (internas)' : 'Transferencias enviadas (internas)';
                    $transferKey = ($direction >= 0 ? 'in:' : 'out:') . $label;
                    $addAmount($transfersByCategory, $transferKey, $periodKey, $amount);
                    continue;
                }

                $conceptId = $mov->treasury_concept_id !== null ? (int) $mov->treasury_concept_id : null;
                if ($isTransfer) {
                    $category = $kind === 'transfer_in' ? 'Transferencias recibidas' : 'Transferencias enviadas';
                } elseif ($conceptId !== null && isset($conceptsMap[$conceptId])) {
                    $category = $conceptsMap[$conceptId]['description'];
                } else {
                    $category = MovementService::KIND_LABELS[$kind] ?? ucfirst($kind);
                }

                if ($direction >= 0) {
                    $addAmount($inflowsByCategory, $category, $periodKey, $amount);
                } else {
                    $addAmount($outflowsByCategory, $category, $periodKey, $amount);
                }
            }
        }

        // 2. Accepted global_payments across all 9 payment tables.
        // Non-admins reading SHARED bank accounts (establishment_id IS NULL) only see payments whose parent document
        // belongs to their establishment (parents without an establishment cannot be attributed and stay visible),
        // the same way treasury movements are filtered above. Admins and establishment-owned accounts are unchanged.
        // Account-level opening balances still come from the balance service and include every establishment.
        $sharedBankAccountIds = [];
        $sharedScopeEstablishmentId = null;
        if (!$resolvedScope['is_admin'] && $effectiveEstId !== null) {
            $sharedScopeEstablishmentId = $effectiveEstId;
            foreach ($bankAccounts as $acc) {
                if ($acc->establishment_id === null) {
                    $sharedBankAccountIds[] = (int) $acc->id;
                }
            }
        }
        $globalEntries = $this->loadGlobalPaymentEntries(
            $dateStart,
            $dateEnd,
            $includeBankAccounts ? $bankAccountIds : [],
            $includeCash,
            $scopedCashIds,
            $sharedBankAccountIds,
            $sharedScopeEstablishmentId
        );

        foreach ($globalEntries as $entry) {
            $periodKey = $this->resolvePeriodKey($entry['date'], $periods);
            if ($periodKey === null) {
                continue;
            }

            $converted = $this->convertLegacyPaymentAmount(
                $entry['amount'],
                $entry['currency_type_id'],
                $entry['exchange_rate_sale'],
                $isConsolidated,
                $reportCurrency,
                $fallbackSaleRate,
                $missingExchangeRate
            );
            if ($converted <= 0.0) {
                continue;
            }

            $meta = self::LEGACY_PAYMENT_CATEGORIES[$entry['payment_type']] ?? ['label' => 'Otros movimientos', 'direction' => 1];
            if ($meta['direction'] >= 0) {
                $addAmount($inflowsByCategory, $meta['label'], $periodKey, $converted);
            } else {
                $addAmount($outflowsByCategory, $meta['label'], $periodKey, $converted);
            }
        }

        // 3. Credit-note adjustments. Balances (opening and closing) deduct credit notes from the documents they
        // were collected on, but the period flows above are gross payments. Without an explicit line the screen
        // would show opening + flows != balance as of date_end.
        $creditNoteAdjustments = $this->loadCreditNoteAdjustments(
            $dateStart,
            $dateEnd,
            $dayBeforeStart,
            $includeBankAccounts ? $bankAccounts : [],
            $includeCash && ($scopedCashIds === null || !empty($scopedCashIds)),
            $effectiveEstId,
            $resolvedScope['is_admin'] ? null : $resolvedScope['user_id']
        );
        foreach ($creditNoteAdjustments as $adj) {
            $periodKey = $this->resolvePeriodKey($adj['date'], $periods) ?? ($periods[0]['key'] ?? null);
            if ($periodKey === null) {
                continue;
            }
            $amount = $adj['amount'];
            if ($adj['currency_type_id'] === 'USD' && $isConsolidated) {
                if ($fallbackPurchaseRate > 0) {
                    $amount = Money::round($adj['amount'] * $fallbackPurchaseRate);
                } else {
                    $missingExchangeRate = true;
                    continue;
                }
            }
            $addAmount($outflowsByCategory, self::CREDIT_NOTE_ADJUSTMENT_LABEL, $periodKey, $amount);
        }

        // Format rows and period summaries
        $inflowRows = $this->formatMatrixRows($inflowsByCategory, $periods);
        $outflowRows = $this->formatMatrixRows($outflowsByCategory, $periods);

        $transferRows = [];
        foreach ($transfersByCategory as $key => $values) {
            $isIn = strpos($key, 'in:') === 0;
            $label = substr($key, $isIn ? 3 : 4);
            $transferRows[] = [
                'category' => $label,
                'direction' => $isIn ? 1 : -1,
                'values' => $values,
                'total' => Money::round((float) array_sum($values)),
            ];
        }

        $periodSummary = [];
        $running = $openingBalance;
        $totalInflows = 0.0;
        $totalOutflows = 0.0;
        $totalTransfersNet = 0.0;

        foreach ($periods as $p) {
            $pKey = $p['key'];
            $pIn = 0.0;
            foreach ($inflowRows as $row) {
                $pIn += (float) ($row['values'][$pKey] ?? 0.0);
            }
            $pOut = 0.0;
            foreach ($outflowRows as $row) {
                $pOut += (float) ($row['values'][$pKey] ?? 0.0);
            }
            $pTransfersNet = 0.0;
            foreach ($transferRows as $row) {
                $pTransfersNet += $row['direction'] * (float) ($row['values'][$pKey] ?? 0.0);
            }

            $pIn = Money::round($pIn);
            $pOut = Money::round($pOut);
            $pTransfersNet = Money::round($pTransfersNet);
            $pNet = Money::round($pIn - $pOut + $pTransfersNet);
            $pOpening = $running;
            $pClosing = Money::round($pOpening + $pNet);

            $periodSummary[] = [
                'key' => $pKey,
                'label' => $p['label'],
                'start' => $p['start'],
                'end' => $p['end'],
                'opening_balance' => $pOpening,
                'inflows' => $pIn,
                'outflows' => $pOut,
                'transfers_net' => $pTransfersNet,
                'net' => $pNet,
                'closing_balance' => $pClosing,
            ];

            $running = $pClosing;
            $totalInflows = Money::round($totalInflows + $pIn);
            $totalOutflows = Money::round($totalOutflows + $pOut);
            $totalTransfersNet = Money::round($totalTransfersNet + $pTransfersNet);
        }

        $totalNet = Money::round($totalInflows - $totalOutflows + $totalTransfersNet);
        $closingBalance = Money::round($openingBalance + $totalNet);

        return [
            'mode' => 'real',
            'currency_type_id' => $reportCurrency,
            'is_consolidated' => $isConsolidated,
            'granularity' => $granularity,
            'destination_type' => $destinationType,
            'establishment_id' => $effectiveEstId,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'missing_exchange_rate' => $missingExchangeRate,
            'periods' => $periods,
            'opening_balance' => $openingBalance,
            'inflow_rows' => $inflowRows,
            'outflow_rows' => $outflowRows,
            'transfer_rows' => $transferRows,
            'period_summary' => $periodSummary,
            'totals' => [
                'opening_balance' => $openingBalance,
                'inflows' => $totalInflows,
                'outflows' => $totalOutflows,
                'transfers_net' => $totalTransfersNet,
                'net' => $totalNet,
                'closing_balance' => $closingBalance,
            ],
        ];
    }

    /**
     * Projected Cash Flow combining current liquidity, pending AR/AP installments,
     * pending payable SPOT detractions, and optional deferred instruments (Phase 7 guard).
     */
    public function getProjectedCashFlow(array $filters, array $scope, ?string $asOfDate = null): array
    {
        $this->tableExistsCache = [];
        $asOf = $asOfDate ?: Carbon::today()->format('Y-m-d');
        $dateStart = !empty($filters['date_start']) ? (string) $filters['date_start'] : $asOf;
        $dateEnd = !empty($filters['date_end'])
            ? (string) $filters['date_end']
            : Carbon::parse($dateStart)->addDays(60)->format('Y-m-d');

        $granularity = isset($filters['granularity']) && in_array($filters['granularity'], ['week', 'month'], true)
            ? (string) $filters['granularity']
            : 'week';
        $this->assertValidDateRange($dateStart, $dateEnd, $granularity);

        $includeOverdue = array_key_exists('include_overdue', $filters) ? (bool) $filters['include_overdue'] : true;

        [$isConsolidated, $filterCurrency, $reportCurrency] = $this->normalizeCurrencyFilter($filters['currency_type_id'] ?? null);

        $periods = $this->buildPeriods($dateStart, $dateEnd, $granularity);
        if ($includeOverdue) {
            array_unshift($periods, [
                'key' => 'overdue',
                'label' => 'Vencido',
                'start' => null,
                'end' => Carbon::parse($dateStart)->subDay()->format('Y-m-d'),
                'is_overdue' => true,
            ]);
        }

        $resolvedScope = $this->resolveScope($filters, $scope);
        if (!$resolvedScope['allowed']) {
            return $this->emptyProjectedCashFlow($dateStart, $dateEnd, $asOf, $granularity, $reportCurrency, $isConsolidated, $includeOverdue, null, $periods);
        }

        $effectiveEstId = $resolvedScope['establishment_id'];
        $missingExchangeRate = false;
        $fallbackSaleRate = $this->resolveExchangeRateSale($asOf);

        // Starting liquidity from TreasuryBalanceService.
        // Non-admins only include their own POS cash boxes (never other cashiers' cashes in the establishment).
        $summary = $this->balanceService->getSummary([
            'establishment_id' => $effectiveEstId,
            'currency_type_id' => $filterCurrency,
        ]);

        if ($isConsolidated) {
            if (!empty($summary['totals']['missing_exchange_rate'])) {
                $missingExchangeRate = true;
            }
            if ($resolvedScope['is_admin']) {
                $openingBalance = Money::round((float) ($summary['totals']['consolidated_pen'] ?? 0.0));
            } else {
                $banksPen = (float) ($summary['totals']['banks_pen'] ?? 0.0) + (float) ($summary['totals']['banks_usd_in_pen'] ?? 0.0);
                $ownCashPen = $this->balanceService->getCashBalanceAsOf($asOf, $effectiveEstId, null, true, $resolvedScope['user_id']);
                $openingBalance = Money::round($banksPen + $ownCashPen);
            }
        } elseif ($reportCurrency === 'PEN') {
            $banksPen = (float) ($summary['totals']['banks_pen'] ?? 0.0);
            $cashPen = $resolvedScope['is_admin']
                ? (float) ($summary['totals']['cash_pen'] ?? 0.0)
                : $this->balanceService->getCashBalanceAsOf($asOf, $effectiveEstId, null, true, $resolvedScope['user_id']);
            $openingBalance = Money::round($banksPen + $cashPen);
        } else {
            $openingBalance = Money::round((float) ($summary['totals']['banks_usd'] ?? 0.0));
        }

        $inflowsByCategory = [];
        $outflowsByCategory = [];

        $addAmount = function (array &$bucket, string $category, string $periodKey, float $amount) use ($periods): void {
            if ($amount <= 0.0) {
                return;
            }
            if (!isset($bucket[$category])) {
                $bucket[$category] = [];
                foreach ($periods as $p) {
                    $bucket[$category][$p['key']] = 0.0;
                }
            }
            $bucket[$category][$periodKey] = Money::round(($bucket[$category][$periodKey] ?? 0.0) + $amount);
        };

        $bucketForDueDate = function (string $dueDate) use ($dateStart, $dateEnd, $includeOverdue, $periods): ?string {
            if ($dueDate > $dateEnd) {
                return null;
            }
            if ($dueDate < $dateStart) {
                return $includeOverdue ? 'overdue' : null;
            }

            return $this->resolvePeriodKey($dueDate, $periods);
        };

        // 1. Pending payable detractions (loaded first so we can avoid double-counting against purchase installments)
        $pendingDetractionByPurchasePen = [];
        $pendingDetractions = $this->loadPendingPayableDetractions(
            $effectiveEstId,
            $resolvedScope['is_admin'],
            $resolvedScope['user_id']
        );
        foreach ($pendingDetractions as $dr) {
            $purchaseId = (int) $dr->source_id;
            $amountPen = Money::round((float) $dr->amount_pen);
            if ($amountPen <= 0.0) {
                continue;
            }

            $pendingDetractionByPurchasePen[$purchaseId] = Money::round(
                ($pendingDetractionByPurchasePen[$purchaseId] ?? 0.0) + $amountPen
            );

            // Detractions are always deposited in PEN (BN SPOT account)
            if (!$isConsolidated && $reportCurrency !== 'PEN') {
                continue;
            }

            $dueDate = $dr->due_date
                ? substr((string) $dr->due_date, 0, 10)
                : ($dr->registry_date ? substr((string) $dr->registry_date, 0, 10) : $asOf);
            $periodKey = $bucketForDueDate($dueDate);
            if ($periodKey !== null) {
                $addAmount($outflowsByCategory, 'Detracciones por depositar (SPOT)', $periodKey, $amountPen);
            }
        }

        // Load receivable detraction records so already-received detractions are never projected again
        // and IGV retentions (non-cash tax credit) are excluded from cash inflows.
        $receivableDetractionsByDoc = $this->loadReceivableDetractionsMap(
            $effectiveEstId,
            $resolvedScope['is_admin'],
            $resolvedScope['user_id']
        );

        // 2. Receivables & Payables from ReceivablePayableService (NO date_start/date_end filter;
        //    admins pass user_id = null so they see obligations created by all users in scope).
        $rpFilters = [
            'is_admin' => $resolvedScope['is_admin'],
            'user_id' => $resolvedScope['is_admin'] ? null : $resolvedScope['user_id'],
            'establishment_id' => $effectiveEstId,
            'currency_type_id' => $filterCurrency,
        ];

        $receivables = $this->receivablePayableService->getReceivables($rpFilters, $asOf);
        foreach ($receivables['rows'] as $row) {
            $rowCurrency = strtoupper((string) ($row['currency_type_id'] ?? 'PEN'));
            $rowRate = isset($row['exchange_rate_sale']) && (float) $row['exchange_rate_sale'] > 0
                ? (float) $row['exchange_rate_sale']
                : null;
            $rowType = (string) ($row['type'] ?? 'document');
            $hasCustomSchedule = !empty($row['has_installments']);

            $effectiveRetention = Money::round(max(
                (float) ($row['retention_amount'] ?? 0.0),
                (float) ($row['retention_registered_amount'] ?? 0.0)
            ));

            $pendingBnDetraction = 0.0;
            $alreadyReceivedDetraction = 0.0;
            if ($rowType === 'document') {
                $docId = (int) ($row['id'] ?? 0);
                if (isset($receivableDetractionsByDoc[$docId])) {
                    $drInfo = $receivableDetractionsByDoc[$docId];
                    $drAmountInCur = $this->convertPenToCurrency($drInfo['amount_pen'], $rowCurrency, $rowRate, $fallbackSaleRate);
                    if ($drInfo['status'] === DetractionRecord::STATUS_PENDING) {
                        $pendingBnDetraction = $drAmountInCur;
                    } else {
                        $alreadyReceivedDetraction = $drAmountInCur;
                    }
                } else {
                    $pendingBnDetraction = Money::round((float) ($row['detraction_amount'] ?? 0.0));
                }
            }

            $remainingNonCashToExclude = $hasCustomSchedule
                ? 0.0
                : Money::round($effectiveRetention + $alreadyReceivedDetraction);
            $remainingPendingBn = $pendingBnDetraction;

            foreach ($row['installments'] as $inst) {
                $pending = Money::round((float) ($inst['pending_amount'] ?? 0.0));
                if ($pending <= 0.0) {
                    continue;
                }

                $dueDate = substr((string) ($inst['date'] ?? $asOf), 0, 10);
                $periodKey = $bucketForDueDate($dueDate);
                if ($periodKey === null) {
                    continue;
                }

                $source = (string) ($inst['source'] ?? 'due_date');
                if ($source === 'detraction_retention') {
                    // Only project pending BN detractions; never project CRE retentions or already-received detractions.
                    $bnAmount = Money::round(min($pending, $remainingPendingBn));
                    $remainingPendingBn = Money::round(max(0.0, $remainingPendingBn - $bnAmount));
                    if ($bnAmount <= 0.0) {
                        continue;
                    }

                    $convertedBn = $this->convertLegacyPaymentAmount(
                        $bnAmount,
                        $rowCurrency,
                        $rowRate,
                        $isConsolidated,
                        $reportCurrency,
                        $fallbackSaleRate,
                        $missingExchangeRate
                    );
                    if ($convertedBn > 0.0) {
                        $addAmount($inflowsByCategory, 'Detracciones por cobrar (BN)', $periodKey, $convertedBn);
                    }
                    continue;
                }

                if (!$hasCustomSchedule && $remainingNonCashToExclude > 0.0) {
                    $deductNonCash = min($pending, $remainingNonCashToExclude);
                    $pending = Money::round($pending - $deductNonCash);
                    $remainingNonCashToExclude = Money::round($remainingNonCashToExclude - $deductNonCash);
                    if ($pending <= 0.0) {
                        continue;
                    }
                }

                if (!$hasCustomSchedule && $remainingPendingBn > 0.0) {
                    $bnSplit = Money::round(min($pending, $remainingPendingBn));
                    $remainingPendingBn = Money::round(max(0.0, $remainingPendingBn - $bnSplit));
                    $pending = Money::round($pending - $bnSplit);
                    if ($bnSplit > 0.0) {
                        $convertedBn = $this->convertLegacyPaymentAmount(
                            $bnSplit,
                            $rowCurrency,
                            $rowRate,
                            $isConsolidated,
                            $reportCurrency,
                            $fallbackSaleRate,
                            $missingExchangeRate
                        );
                        if ($convertedBn > 0.0) {
                            $addAmount($inflowsByCategory, 'Detracciones por cobrar (BN)', $periodKey, $convertedBn);
                        }
                    }
                    if ($pending <= 0.0) {
                        continue;
                    }
                }

                $converted = $this->convertLegacyPaymentAmount(
                    $pending,
                    $rowCurrency,
                    $rowRate,
                    $isConsolidated,
                    $reportCurrency,
                    $fallbackSaleRate,
                    $missingExchangeRate
                );
                if ($converted <= 0.0) {
                    continue;
                }

                $category = $rowType === 'sale_note'
                    ? 'Cobranzas de notas de venta'
                    : 'Cobranzas de clientes (cuotas)';

                $addAmount($inflowsByCategory, $category, $periodKey, $converted);
            }
        }

        $payables = $this->receivablePayableService->getPayables($rpFilters, $asOf);
        foreach ($payables['rows'] as $row) {
            $rowCurrency = strtoupper((string) ($row['currency_type_id'] ?? 'PEN'));
            $rowRate = isset($row['exchange_rate_sale']) && (float) $row['exchange_rate_sale'] > 0
                ? (float) $row['exchange_rate_sale']
                : null;
            $rowType = (string) ($row['type'] ?? 'purchase');

            // If this purchase has a pending payable detraction already placed in "Detracciones por depositar (SPOT)",
            // deduct that portion from the supplier payable so it is never double-counted.
            $remainingDetractionToDeduct = 0.0;
            if ($rowType === 'purchase') {
                $purchaseId = (int) ($row['id'] ?? 0);
                $pendingDetPen = (float) ($pendingDetractionByPurchasePen[$purchaseId] ?? 0.0);
                if ($pendingDetPen > 0.0) {
                    if ($rowCurrency === 'PEN') {
                        $remainingDetractionToDeduct = $pendingDetPen;
                    } elseif ($rowCurrency === 'USD') {
                        $rate = $rowRate ?? ($fallbackSaleRate > 0 ? $fallbackSaleRate : null);
                        if ($rate !== null && $rate > 0) {
                            $remainingDetractionToDeduct = Money::round($pendingDetPen / $rate);
                        }
                    }
                }
            }

            foreach ($row['installments'] as $inst) {
                $source = (string) ($inst['source'] ?? 'due_date');
                // Exclude synthetic detraction_retention rows so they are never double-counted against detraction_records
                if ($source === 'detraction_retention') {
                    continue;
                }

                $pending = Money::round((float) ($inst['pending_amount'] ?? 0.0));
                if ($pending <= 0.0) {
                    continue;
                }

                if ($remainingDetractionToDeduct > 0.0) {
                    $deduct = min($pending, $remainingDetractionToDeduct);
                    $pending = Money::round($pending - $deduct);
                    $remainingDetractionToDeduct = Money::round($remainingDetractionToDeduct - $deduct);
                    if ($pending <= 0.0) {
                        continue;
                    }
                }

                $dueDate = substr((string) ($inst['date'] ?? $asOf), 0, 10);
                $periodKey = $bucketForDueDate($dueDate);
                if ($periodKey === null) {
                    continue;
                }

                $converted = $this->convertLegacyPaymentAmount(
                    $pending,
                    $rowCurrency,
                    $rowRate,
                    $isConsolidated,
                    $reportCurrency,
                    $fallbackSaleRate,
                    $missingExchangeRate
                );
                if ($converted <= 0.0) {
                    continue;
                }

                $category = $rowType === 'expense' ? 'Pagos de gastos' : 'Pagos a proveedores';
                $addAmount($outflowsByCategory, $category, $periodKey, $converted);
            }
        }

        // 3. Optional Phase 7 instruments (cheques / bills) — guarded with hasTable and user/establishment scope
        foreach ($this->loadPendingInstruments($filterCurrency, $resolvedScope['is_admin'], $effectiveEstId, $resolvedScope['user_id']) as $inst) {
            $dueDate = substr((string) ($inst->due_date ?? $asOf), 0, 10);
            $periodKey = $bucketForDueDate($dueDate);
            if ($periodKey === null) {
                continue;
            }
            $converted = $this->convertLegacyPaymentAmount(
                (float) $inst->amount,
                strtoupper((string) ($inst->currency_type_id ?: 'PEN')),
                null,
                $isConsolidated,
                $reportCurrency,
                $fallbackSaleRate,
                $missingExchangeRate
            );
            if ($converted <= 0.0) {
                continue;
            }

            $type = (string) $inst->type;
            if (in_array($type, ['cheque_received', 'bill_receivable'], true)) {
                $addAmount($inflowsByCategory, 'Cheques y letras por cobrar', $periodKey, $converted);
            } else {
                $addAmount($outflowsByCategory, 'Cheques y letras por pagar', $periodKey, $converted);
            }
        }

        $inflowRows = $this->formatMatrixRows($inflowsByCategory, $periods);
        $outflowRows = $this->formatMatrixRows($outflowsByCategory, $periods);

        $periodSummary = [];
        $negativePeriods = [];
        $running = $openingBalance;
        $totalInflows = 0.0;
        $totalOutflows = 0.0;

        foreach ($periods as $p) {
            $pKey = $p['key'];
            $pIn = 0.0;
            foreach ($inflowRows as $row) {
                $pIn += (float) ($row['values'][$pKey] ?? 0.0);
            }
            $pOut = 0.0;
            foreach ($outflowRows as $row) {
                $pOut += (float) ($row['values'][$pKey] ?? 0.0);
            }

            $pIn = Money::round($pIn);
            $pOut = Money::round($pOut);
            $pNet = Money::round($pIn - $pOut);
            $pOpening = $running;
            $pClosing = Money::round($pOpening + $pNet);

            $periodSummary[] = [
                'key' => $pKey,
                'label' => $p['label'],
                'start' => $p['start'],
                'end' => $p['end'],
                'is_overdue' => !empty($p['is_overdue']),
                'opening_balance' => $pOpening,
                'inflows' => $pIn,
                'outflows' => $pOut,
                'net' => $pNet,
                'closing_balance' => $pClosing,
                'is_negative' => $pClosing < 0.0,
            ];

            if ($pClosing < 0.0) {
                $negativePeriods[] = [
                    'key' => $pKey,
                    'label' => $p['label'],
                    'closing_balance' => $pClosing,
                ];
            }

            $running = $pClosing;
            $totalInflows = Money::round($totalInflows + $pIn);
            $totalOutflows = Money::round($totalOutflows + $pOut);
        }

        $totalNet = Money::round($totalInflows - $totalOutflows);
        $closingBalance = Money::round($openingBalance + $totalNet);

        return [
            'mode' => 'projected',
            'as_of_date' => $asOf,
            'currency_type_id' => $reportCurrency,
            'is_consolidated' => $isConsolidated,
            'granularity' => $granularity,
            'include_overdue' => $includeOverdue,
            'establishment_id' => $effectiveEstId,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'missing_exchange_rate' => $missingExchangeRate,
            'periods' => $periods,
            'opening_balance' => $openingBalance,
            'inflow_rows' => $inflowRows,
            'outflow_rows' => $outflowRows,
            'period_summary' => $periodSummary,
            'has_negative_projection' => !empty($negativePeriods),
            'negative_periods' => $negativePeriods,
            'totals' => [
                'opening_balance' => $openingBalance,
                'inflows' => $totalInflows,
                'outflows' => $totalOutflows,
                'net' => $totalNet,
                'closing_balance' => $closingBalance,
            ],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Internal Helpers
    // ---------------------------------------------------------------------------------------------------------

    private function assertValidDateRange(string $dateStart, string $dateEnd, string $granularity = 'month'): void
    {
        if ($dateStart > $dateEnd) {
            throw ValidationException::withMessages([
                'date_end' => ['La fecha final debe ser igual o posterior a la fecha inicial.'],
            ]);
        }

        $days = Carbon::parse($dateStart)->diffInDays(Carbon::parse($dateEnd));
        if ($granularity === 'week' && $days > self::MAX_WEEKLY_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'date_end' => ['Para granularidad semanal el rango máximo permitido es de 731 días (2 años).'],
            ]);
        }
        if ($granularity === 'month' && $days > self::MAX_MONTHLY_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'date_end' => ['Para granularidad mensual el rango máximo permitido es de 1826 días (5 años).'],
            ]);
        }
    }

    /**
     * @return array{0: bool, 1: ?string, 2: string} [isConsolidated, filterCurrency, reportCurrency]
     */
    private function normalizeCurrencyFilter($raw): array
    {
        $clean = $raw !== null ? strtoupper(trim((string) $raw)) : '';
        if ($clean === '' || $clean === 'ALL') {
            return [true, null, 'PEN'];
        }

        return [false, $clean, $clean];
    }

    /**
     * @return array{allowed: bool, is_admin: bool, user_id: ?int, establishment_id: ?int}
     */
    private function resolveScope(array $filters, array $scope): array
    {
        $isAdmin = !empty($scope['is_admin']);
        $userId = isset($scope['user_id']) && (int) $scope['user_id'] > 0 ? (int) $scope['user_id'] : null;
        $scopeEstId = isset($scope['establishment_id']) && (int) $scope['establishment_id'] > 0
            ? (int) $scope['establishment_id']
            : null;

        if (!$isAdmin) {
            if ($scopeEstId === null || $scopeEstId <= 0 || $userId === null) {
                return ['allowed' => false, 'is_admin' => false, 'user_id' => $userId, 'establishment_id' => null];
            }

            return ['allowed' => true, 'is_admin' => false, 'user_id' => $userId, 'establishment_id' => $scopeEstId];
        }

        $requestedEstId = isset($filters['establishment_id']) && $filters['establishment_id'] !== '' && (int) $filters['establishment_id'] > 0
            ? (int) $filters['establishment_id']
            : null;

        return ['allowed' => true, 'is_admin' => true, 'user_id' => $userId, 'establishment_id' => $requestedEstId];
    }

    /**
     * @return array<int, array{key: string, label: string, start: string, end: string}>
     */
    private function buildPeriods(string $dateStart, string $dateEnd, string $granularity): array
    {
        $periods = [];
        $start = Carbon::parse($dateStart)->startOfDay();
        $end = Carbon::parse($dateEnd)->startOfDay();

        if ($granularity === 'week') {
            $cursor = $start->copy()->startOfWeek(Carbon::MONDAY);
            while ($cursor->lte($end)) {
                $bucketStart = $cursor->lt($start) ? $start->format('Y-m-d') : $cursor->format('Y-m-d');
                $weekEnd = $cursor->copy()->endOfWeek(Carbon::SUNDAY);
                $bucketEnd = $weekEnd->gt($end) ? $end->format('Y-m-d') : $weekEnd->format('Y-m-d');
                $key = $cursor->format('o-\WW');
                $label = 'Sem ' . $cursor->format('W/o') . ' (' . Carbon::parse($bucketStart)->format('d/m') . '-' . Carbon::parse($bucketEnd)->format('d/m') . ')';

                $periods[] = [
                    'key' => $key,
                    'label' => $label,
                    'start' => $bucketStart,
                    'end' => $bucketEnd,
                ];
                $cursor->addWeek();
            }

            return $periods;
        }

        $cursor = $start->copy()->startOfMonth();
        while ($cursor->lte($end)) {
            $bucketStart = $cursor->lt($start) ? $start->format('Y-m-d') : $cursor->format('Y-m-d');
            $monthEnd = $cursor->copy()->endOfMonth();
            $bucketEnd = $monthEnd->gt($end) ? $end->format('Y-m-d') : $monthEnd->format('Y-m-d');
            $monthName = self::SPANISH_MONTHS[(int) $cursor->month] ?? $cursor->format('m');

            $periods[] = [
                'key' => $cursor->format('Y-m'),
                'label' => $monthName . ' ' . $cursor->format('Y'),
                'start' => $bucketStart,
                'end' => $bucketEnd,
            ];
            $cursor->addMonth();
        }

        return $periods;
    }

    private function resolvePeriodKey(string $date, array $periods): ?string
    {
        foreach ($periods as $p) {
            if (!empty($p['is_overdue'])) {
                continue;
            }
            if ($date >= $p['start'] && $date <= $p['end']) {
                return $p['key'];
            }
        }

        return null;
    }

    private function loadScopedBankAccounts(?int $establishmentId, ?string $filterCurrency): array
    {
        if (!$this->hasTable('bank_accounts')) {
            return [];
        }

        $q = DB::connection('tenant')->table('bank_accounts')->orderBy('id');
        if ($establishmentId !== null) {
            $q->where(function ($sub) use ($establishmentId) {
                $sub->where('establishment_id', $establishmentId)->orWhereNull('establishment_id');
            });
        }
        if ($filterCurrency !== null) {
            $q->where('currency_type_id', $filterCurrency);
        }

        return $q->get(['id', 'currency_type_id', 'establishment_id'])->all();
    }

    /**
     * @return ?array<int, int> null = all cashes (unscoped admin)
     */
    private function resolveScopedCashIds(bool $isAdmin, ?int $establishmentId, ?int $userId): ?array
    {
        if (!$this->hasTable('cash')) {
            return [];
        }

        if (!$isAdmin) {
            if ($userId === null || $establishmentId === null) {
                return [];
            }

            $q = DB::connection('tenant')
                ->table('cash')
                ->where('cash.user_id', $userId);

            if ($this->hasTable('users')) {
                $q->join('users', 'users.id', '=', 'cash.user_id')
                    ->where('users.establishment_id', $establishmentId);
            }

            return $q->pluck('cash.id')->map('intval')->all();
        }

        if ($establishmentId === null) {
            return null;
        }

        if ($this->hasTable('users')) {
            return DB::connection('tenant')
                ->table('cash')
                ->join('users', 'users.id', '=', 'cash.user_id')
                ->where('users.establishment_id', $establishmentId)
                ->pluck('cash.id')
                ->map('intval')
                ->all();
        }

        return [];
    }

    /**
     * Loads accepted global_payments across all 9 payment tables within [date_start, date_end].
     *
     * @return array<int, array{date: string, payment_type: string, amount: float, currency_type_id: string, exchange_rate_sale: ?float}>
     */
    private function loadGlobalPaymentEntries(
        string $dateStart,
        string $dateEnd,
        array $bankAccountIds,
        bool $includeCash,
        ?array $scopedCashIds,
        array $sharedBankAccountIds = [],
        ?int $sharedScopeEstablishmentId = null
    ): array {
        if (!$this->hasTable('global_payments')) {
            return [];
        }

        $hasBanks = !empty($bankAccountIds);
        $hasCash = $includeCash && ($scopedCashIds === null || !empty($scopedCashIds));
        if (!$hasBanks && !$hasCash) {
            return [];
        }

        $applyDestinationScope = function ($query, bool $allowBank = true) use ($hasBanks, $bankAccountIds, $hasCash, $scopedCashIds): void {
            $query->where(function ($q) use ($allowBank, $hasBanks, $bankAccountIds, $hasCash, $scopedCashIds) {
                $added = false;
                if ($allowBank && $hasBanks) {
                    $q->where(function ($b) use ($bankAccountIds) {
                        $b->where('gp.destination_type', BankAccount::class)
                            ->whereIn('gp.destination_id', $bankAccountIds);
                    });
                    $added = true;
                }
                if ($hasCash) {
                    $method = $added ? 'orWhere' : 'where';
                    $q->{$method}(function ($c) use ($scopedCashIds) {
                        $c->where('gp.destination_type', Cash::class);
                        if ($scopedCashIds !== null) {
                            $c->whereIn('gp.destination_id', $scopedCashIds);
                        }
                    });
                    $added = true;
                }
                if (!$added) {
                    $q->whereRaw('1 = 0');
                }
            });
        };

        $entries = [];
        $incomeTable = $this->resolveIncomeTable();

        $specs = [
            ['class' => DocumentPayment::class, 'pay' => 'document_payments', 'fk' => 'document_id', 'parent' => 'documents', 'has_state' => true, 'has_changed' => false, 'has_currency' => true],
            ['class' => SaleNotePayment::class, 'pay' => 'sale_note_payments', 'fk' => 'sale_note_id', 'parent' => 'sale_notes', 'has_state' => true, 'has_changed' => true, 'has_currency' => true],
            ['class' => QuotationPayment::class, 'pay' => 'quotation_payments', 'fk' => 'quotation_id', 'parent' => 'quotations', 'has_state' => true, 'has_changed' => true, 'has_currency' => true],
            ['class' => ContractPayment::class, 'pay' => 'contract_payments', 'fk' => 'contract_id', 'parent' => 'contracts', 'has_state' => true, 'has_changed' => true, 'has_currency' => true],
            ['class' => IncomePayment::class, 'pay' => 'income_payments', 'fk' => 'income_id', 'parent' => $incomeTable, 'has_state' => true, 'has_changed' => false, 'has_currency' => true],
            ['class' => TechnicalServicePayment::class, 'pay' => 'technical_service_payments', 'fk' => 'technical_service_id', 'parent' => 'technical_services', 'has_state' => false, 'has_changed' => false, 'has_currency' => false],
            ['class' => PurchasePayment::class, 'pay' => 'purchase_payments', 'fk' => 'purchase_id', 'parent' => 'purchases', 'has_state' => true, 'has_changed' => false, 'has_currency' => true],
            ['class' => ExpensePayment::class, 'pay' => 'expense_payments', 'fk' => 'expense_id', 'parent' => 'expenses', 'has_state' => true, 'has_changed' => false, 'has_currency' => true],
        ];

        foreach ($specs as $spec) {
            if (empty($spec['parent']) || !$this->hasTable($spec['pay']) || !$this->hasTable($spec['parent'])) {
                continue;
            }

            $q = DB::connection('tenant')
                ->table('global_payments as gp')
                ->join($spec['pay'] . ' as p', 'p.id', '=', 'gp.payment_id')
                ->join($spec['parent'] . ' as par', 'par.id', '=', 'p.' . $spec['fk'])
                ->where('gp.payment_type', $spec['class'])
                ->where('p.date_of_payment', '>=', $dateStart)
                ->where('p.date_of_payment', '<=', $dateEnd);

            $applyDestinationScope($q, true);

            if ($sharedScopeEstablishmentId !== null && !empty($sharedBankAccountIds) && $this->hasColumn($spec['parent'], 'establishment_id')) {
                $q->where(function ($w) use ($sharedBankAccountIds, $sharedScopeEstablishmentId) {
                    $w->where('gp.destination_type', '!=', BankAccount::class)
                        ->orWhereNotIn('gp.destination_id', $sharedBankAccountIds)
                        ->orWhere('par.establishment_id', $sharedScopeEstablishmentId)
                        ->orWhereNull('par.establishment_id');
                });
            }

            if ($spec['has_state']) {
                $q->whereIn('par.state_type_id', TreasuryBalanceService::ACCEPTED_STATE_TYPES);
            }
            if ($spec['has_changed']) {
                $q->where('par.changed', false);
            }

            $cols = ['p.date_of_payment', 'p.payment'];
            if ($spec['has_currency']) {
                $cols[] = 'par.currency_type_id';
                $cols[] = 'par.exchange_rate_sale';
            }

            foreach ($q->get($cols) as $row) {
                $entries[] = [
                    'date' => substr((string) $row->date_of_payment, 0, 10),
                    'payment_type' => $spec['class'],
                    'amount' => (float) $row->payment,
                    'currency_type_id' => $spec['has_currency'] ? strtoupper((string) ($row->currency_type_id ?: 'PEN')) : 'PEN',
                    'exchange_rate_sale' => ($spec['has_currency'] && isset($row->exchange_rate_sale) && (float) $row->exchange_rate_sale > 0)
                        ? (float) $row->exchange_rate_sale
                        : null,
                ];
            }
        }

        // 9. CashTransaction (Cash POS only; uses `date` column)
        if ($hasCash && $this->hasTable('cash_transactions')) {
            $txQuery = DB::connection('tenant')
                ->table('global_payments as gp')
                ->join('cash_transactions as ct', 'ct.id', '=', 'gp.payment_id')
                ->where('gp.payment_type', CashTransaction::class)
                ->where('ct.date', '>=', $dateStart)
                ->where('ct.date', '<=', $dateEnd);

            $applyDestinationScope($txQuery, false);

            foreach ($txQuery->get(['ct.date', 'ct.payment']) as $row) {
                $entries[] = [
                    'date' => substr((string) $row->date, 0, 10),
                    'payment_type' => CashTransaction::class,
                    'amount' => (float) $row->payment,
                    'currency_type_id' => 'PEN',
                    'exchange_rate_sale' => 1.0,
                ];
            }
        }

        return $entries;
    }

    /**
     * Credit-note deductions that the balances apply between the opening date and date_end, as positive amounts.
     *
     * The balance deducts a credit note once per (destination, document) when the document has accepted payments up
     * to the as-of date and the credit note was issued on or before it. The period adjustment is therefore the
     * difference between the adjustment sets as of date_end and as of the day before date_start. Most items are
     * credit notes issued inside the period; a credit note issued before the period on a document first collected
     * inside it is also deducted by the closing balance and is bucketed into the first period.
     *
     * @param array<int, object> $bankAccounts
     *
     * @return array<int, array{date: string, amount: float, currency_type_id: string}>
     */
    private function loadCreditNoteAdjustments(
        string $dateStart,
        string $dateEnd,
        string $dayBeforeStart,
        array $bankAccounts,
        bool $includeCash,
        ?int $establishmentId,
        ?int $userId
    ): array {
        $items = [];

        $diff = function (array $atEnd, array $atOpening): array {
            $known = [];
            foreach ($atOpening as $row) {
                $known[$row['key']] = true;
            }

            return array_values(array_filter($atEnd, function ($row) use ($known) {
                return !isset($known[$row['key']]);
            }));
        };

        foreach ($bankAccounts as $acc) {
            $atEnd = $this->balanceService->getBankAccountCreditNoteAdjustments((int) $acc->id, $dateEnd);
            if (empty($atEnd)) {
                continue;
            }
            $atOpening = $this->balanceService->getBankAccountCreditNoteAdjustments((int) $acc->id, $dayBeforeStart);
            foreach ($diff($atEnd, $atOpening) as $row) {
                $items[] = [
                    'date' => (string) $row['date'],
                    'amount' => Money::round(abs((float) $row['amount'])),
                    'currency_type_id' => (string) $row['currency_type_id'],
                ];
            }
        }

        if ($includeCash) {
            $atEnd = $this->balanceService->getCashCreditNoteAdjustments(null, $dateEnd, $establishmentId, null, $userId);
            if (!empty($atEnd)) {
                $atOpening = $this->balanceService->getCashCreditNoteAdjustments(null, $dayBeforeStart, $establishmentId, null, $userId);
                foreach ($diff($atEnd, $atOpening) as $row) {
                    $items[] = [
                        'date' => (string) $row['date'],
                        'amount' => Money::round(abs((float) $row['amount'])),
                        'currency_type_id' => 'PEN',
                    ];
                }
            }
        }

        return $items;
    }

    private function loadPendingPayableDetractions(?int $establishmentId, bool $isAdmin, ?int $userId): array
    {
        if (!$this->hasTable('detraction_records') || !$this->hasTable('purchases')) {
            return [];
        }

        $q = DB::connection('tenant')
            ->table('detraction_records as dr')
            ->join('purchases as p', 'p.id', '=', 'dr.source_id')
            ->where('dr.side', DetractionRecord::SIDE_PAYABLE)
            ->where('dr.status', DetractionRecord::STATUS_PENDING)
            ->where('dr.source_type', Purchase::class)
            ->whereIn('p.state_type_id', TreasuryBalanceService::ACCEPTED_STATE_TYPES);

        if ($establishmentId !== null) {
            $q->where('dr.establishment_id', $establishmentId);
        }
        if (!$isAdmin) {
            if ($userId === null) {
                return [];
            }
            $q->where('p.user_id', $userId);
        }

        return $q->get([
            'dr.id',
            'dr.source_id',
            'dr.amount_pen',
            'dr.due_date',
            'dr.registry_date',
        ])->all();
    }

    /**
     * @return array<int, array{status: string, amount_pen: float}>
     */
    private function loadReceivableDetractionsMap(?int $establishmentId, bool $isAdmin, ?int $userId): array
    {
        if (!$this->hasTable('detraction_records') || !$this->hasTable('documents')) {
            return [];
        }

        $q = DB::connection('tenant')
            ->table('detraction_records as dr')
            ->join('documents as d', 'd.id', '=', 'dr.source_id')
            ->where('dr.side', DetractionRecord::SIDE_RECEIVABLE)
            ->where('dr.source_type', Document::class)
            ->where('dr.status', '!=', DetractionRecord::STATUS_SKIPPED)
            ->whereIn('d.state_type_id', TreasuryBalanceService::ACCEPTED_STATE_TYPES);

        if ($establishmentId !== null) {
            $q->where('dr.establishment_id', $establishmentId);
        }
        if (!$isAdmin && $userId !== null) {
            $q->where('d.user_id', $userId);
        }

        $map = [];
        foreach ($q->get(['dr.source_id', 'dr.status', 'dr.amount_pen']) as $row) {
            $map[(int) $row->source_id] = [
                'status' => (string) $row->status,
                'amount_pen' => Money::round((float) $row->amount_pen),
            ];
        }

        return $map;
    }

    private function convertPenToCurrency(float $amountPen, string $currency, ?float $documentRateSale, float $fallbackSaleRate): float
    {
        if ($amountPen <= 0.0) {
            return 0.0;
        }
        if ($currency === 'PEN') {
            return Money::round($amountPen);
        }
        $rate = ($documentRateSale !== null && $documentRateSale > 0) ? $documentRateSale : $fallbackSaleRate;
        if ($currency === 'USD' && $rate > 0) {
            return Money::round($amountPen / $rate);
        }

        return 0.0;
    }

    private function loadPendingInstruments(?string $filterCurrency, bool $isAdmin, ?int $establishmentId, ?int $userId): array
    {
        if (!$this->hasTable('treasury_instruments')) {
            return [];
        }

        $schema = DB::connection('tenant')->getSchemaBuilder();
        $hasEstCol = $schema->hasColumn('treasury_instruments', 'establishment_id');
        $hasUserCol = $schema->hasColumn('treasury_instruments', 'user_id');

        // Fail closed for non-admins if the future table lacks ownership/establishment scoping columns.
        if (!$isAdmin && !$hasEstCol && !$hasUserCol) {
            return [];
        }

        $q = DB::connection('tenant')
            ->table('treasury_instruments')
            ->whereIn('status', ['issued', 'delivered', 'deposited']);

        if ($filterCurrency !== null) {
            $q->where('currency_type_id', $filterCurrency);
        }
        if ($establishmentId !== null && $hasEstCol) {
            $q->where('establishment_id', $establishmentId);
        }
        if (!$isAdmin && $hasUserCol && $userId !== null) {
            $q->where('user_id', $userId);
        }

        return $q->get(['type', 'due_date', 'amount', 'currency_type_id'])->all();
    }

    private function convertMovementAmount(
        float $amount,
        float $amountPen,
        string $sourceCurrency,
        ?float $exchangeRateUsed,
        bool $isConsolidated,
        string $reportCurrency,
        float $fallbackPurchaseRate,
        bool &$missingExchangeRate
    ): float {
        if ($isConsolidated) {
            if ($sourceCurrency === 'PEN') {
                return Money::round($amount);
            }
            if ($amountPen > 0.0) {
                return Money::round($amountPen);
            }
            if ($sourceCurrency === 'USD') {
                $rate = ($exchangeRateUsed !== null && $exchangeRateUsed > 0) ? $exchangeRateUsed : $fallbackPurchaseRate;
                if ($rate > 0) {
                    return Money::round($amount * $rate);
                }
            }
            $missingExchangeRate = true;

            return 0.0;
        }

        if ($sourceCurrency === $reportCurrency) {
            return Money::round($amount);
        }

        return 0.0;
    }

    private function convertLegacyPaymentAmount(
        float $amount,
        string $sourceCurrency,
        ?float $documentRateSale,
        bool $isConsolidated,
        string $reportCurrency,
        float $fallbackSaleRate,
        bool &$missingExchangeRate
    ): float {
        $targetCurrency = $isConsolidated ? 'PEN' : $reportCurrency;
        if ($sourceCurrency === $targetCurrency) {
            return Money::round($amount);
        }

        $rate = ($documentRateSale !== null && $documentRateSale > 0) ? $documentRateSale : $fallbackSaleRate;
        if ($sourceCurrency === 'USD' && $targetCurrency === 'PEN') {
            if ($rate > 0) {
                return Money::round($amount * $rate);
            }
            $missingExchangeRate = true;

            return 0.0;
        }

        if ($sourceCurrency === 'PEN' && $targetCurrency === 'USD') {
            if ($rate > 0) {
                return Money::round($amount / $rate);
            }
            $missingExchangeRate = true;

            return 0.0;
        }

        // Unknown / unsupported third currency: never fall back silently 1:1.
        $missingExchangeRate = true;

        return 0.0;
    }

    private function loadConceptsMap(): array
    {
        if (!$this->hasTable('treasury_concepts')) {
            return [];
        }

        $map = [];
        foreach (DB::connection('tenant')->table('treasury_concepts')->get(['id', 'description', 'pcge_account']) as $row) {
            $map[(int) $row->id] = [
                'description' => (string) $row->description,
                'pcge_account' => $row->pcge_account ? (string) $row->pcge_account : null,
            ];
        }

        return $map;
    }

    private function formatMatrixRows(array $byCategory, array $periods): array
    {
        ksort($byCategory);
        $rows = [];
        foreach ($byCategory as $category => $values) {
            $normalized = [];
            foreach ($periods as $p) {
                $normalized[$p['key']] = Money::round((float) ($values[$p['key']] ?? 0.0));
            }
            $rows[] = [
                'category' => $category,
                'values' => $normalized,
                'total' => Money::round((float) array_sum($normalized)),
            ];
        }

        return $rows;
    }

    private function resolveIncomeTable(): ?string
    {
        if ($this->hasTable('income')) {
            return 'income';
        }
        if ($this->hasTable('incomes')) {
            return 'incomes';
        }

        return null;
    }

    private function resolveExchangeRatePurchase(?string $asOfDate = null): float
    {
        if (!$this->hasTable('exchange_rates')) {
            return 0.0;
        }

        try {
            $query = ExchangeRate::query()->orderBy('date', 'desc');
            if ($asOfDate) {
                $bounded = (clone $query)->where('date', '<=', $asOfDate)->first();
                if ($bounded) {
                    return (float) ($bounded->purchase ?: $bounded->sale ?: 0.0);
                }
            }
            $latest = $query->first();

            return $latest ? (float) ($latest->purchase ?: $latest->sale ?: 0.0) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function resolveExchangeRateSale(?string $asOfDate = null): float
    {
        if (!$this->hasTable('exchange_rates')) {
            return 0.0;
        }

        try {
            $query = ExchangeRate::query()->orderBy('date', 'desc');
            if ($asOfDate) {
                $bounded = (clone $query)->where('date', '<=', $asOfDate)->first();
                if ($bounded) {
                    return (float) ($bounded->sale ?: $bounded->purchase ?: 0.0);
                }
            }
            $latest = $query->first();

            return $latest ? (float) ($latest->sale ?: $latest->purchase ?: 0.0) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private function hasTable(string $table): bool
    {
        if (!Model::getConnectionResolver()) {
            return false;
        }
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        try {
            return $this->tableExistsCache[$table] = DB::connection('tenant')->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return $this->tableExistsCache[$table] = false;
        }
    }

    /** @var array<string, bool> */
    private $columnExistsCache = [];

    private function hasColumn(string $table, string $column): bool
    {
        if (!$this->hasTable($table)) {
            return false;
        }
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnExistsCache)) {
            return $this->columnExistsCache[$key];
        }

        try {
            return $this->columnExistsCache[$key] = DB::connection('tenant')->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return $this->columnExistsCache[$key] = false;
        }
    }

    private function emptyRealCashFlow(
        string $dateStart,
        string $dateEnd,
        string $granularity,
        string $destinationType,
        string $reportCurrency,
        bool $isConsolidated,
        ?int $establishmentId,
        array $periods
    ): array {
        $summary = array_map(function (array $p) {
            return [
                'key' => $p['key'],
                'label' => $p['label'],
                'start' => $p['start'],
                'end' => $p['end'],
                'opening_balance' => 0.0,
                'inflows' => 0.0,
                'outflows' => 0.0,
                'transfers_net' => 0.0,
                'net' => 0.0,
                'closing_balance' => 0.0,
            ];
        }, $periods);

        return [
            'mode' => 'real',
            'currency_type_id' => $reportCurrency,
            'is_consolidated' => $isConsolidated,
            'granularity' => $granularity,
            'destination_type' => $destinationType,
            'establishment_id' => $establishmentId,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'missing_exchange_rate' => false,
            'periods' => $periods,
            'opening_balance' => 0.0,
            'inflow_rows' => [],
            'outflow_rows' => [],
            'transfer_rows' => [],
            'period_summary' => $summary,
            'totals' => [
                'opening_balance' => 0.0,
                'inflows' => 0.0,
                'outflows' => 0.0,
                'transfers_net' => 0.0,
                'net' => 0.0,
                'closing_balance' => 0.0,
            ],
        ];
    }

    private function emptyProjectedCashFlow(
        string $dateStart,
        string $dateEnd,
        string $asOf,
        string $granularity,
        string $reportCurrency,
        bool $isConsolidated,
        bool $includeOverdue,
        ?int $establishmentId,
        array $periods
    ): array {
        $summary = array_map(function (array $p) {
            return [
                'key' => $p['key'],
                'label' => $p['label'],
                'start' => $p['start'],
                'end' => $p['end'],
                'is_overdue' => !empty($p['is_overdue']),
                'opening_balance' => 0.0,
                'inflows' => 0.0,
                'outflows' => 0.0,
                'net' => 0.0,
                'closing_balance' => 0.0,
                'is_negative' => false,
            ];
        }, $periods);

        return [
            'mode' => 'projected',
            'as_of_date' => $asOf,
            'currency_type_id' => $reportCurrency,
            'is_consolidated' => $isConsolidated,
            'granularity' => $granularity,
            'include_overdue' => $includeOverdue,
            'establishment_id' => $establishmentId,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'missing_exchange_rate' => false,
            'periods' => $periods,
            'opening_balance' => 0.0,
            'inflow_rows' => [],
            'outflow_rows' => [],
            'period_summary' => $summary,
            'has_negative_projection' => false,
            'negative_periods' => [],
            'totals' => [
                'opening_balance' => 0.0,
                'inflows' => 0.0,
                'outflows' => 0.0,
                'net' => 0.0,
                'closing_balance' => 0.0,
            ],
        ];
    }
}
