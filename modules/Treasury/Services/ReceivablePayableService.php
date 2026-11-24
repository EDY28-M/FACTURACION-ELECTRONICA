<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Expense\Models\ExpensePayment;
use Modules\Treasury\Support\Money;

/**
 * Computes accounts receivable/payable schedules, FIFO installment allocation,
 * multi-currency aging analysis, and due/bancarization alerts (Phase 3).
 *
 * Never calls auth() internally; all user/establishment scoping is passed via explicit filters.
 * Uses SQL payment-sum subqueries + batch queries (constant query count, zero N+1) and
 * SQLite/MariaDB-compatible SQL.
 */
class ReceivablePayableService
{
    public const ACCEPTED_STATE_TYPES = ['01', '03', '05', '07', '13'];
    public const RECEIVABLE_DOCUMENT_TYPES = ['01', '03', '08'];
    public const PAYABLE_DOCUMENT_TYPES = ['01', '03', 'GU75', 'NE76'];
    public const DUE_SOON_DAYS = 7;

    public const SYNTHETIC_SOURCES = [
        'detraction_retention',
        'unscheduled_balance',
    ];

    public const AGING_BUCKETS = [
        'current',
        'days_1_30',
        'days_31_60',
        'days_61_90',
        'days_over_90',
    ];

    private $taxRateRepository;
    private $bancarizationChecker;

    /** @var array<string, ?float> */
    private $thresholdCache = [];

    /** @var array<string, bool> */
    private $schemaCache = [];

    public function __construct(
        TaxRateRepository $taxRateRepository,
        BancarizationChecker $bancarizationChecker
    ) {
        $this->taxRateRepository = $taxRateRepository;
        $this->bancarizationChecker = $bancarizationChecker;
    }

    /**
     * Pure deterministic FIFO allocator for an obligation's installment schedule.
     * Scheduled installments are ordered by date ASC, id ASC; synthetic residual rows
     * (`detraction_retention`, `unscheduled_balance`) are allocated after scheduled installments.
     *
     * @param  array<int, array{id?: ?int, date: string, amount: float|int|string, source?: string}>  $installments
     * @return array<int, array{
     *     id: ?int,
     *     date: string,
     *     amount: float,
     *     paid_amount: float,
     *     pending_amount: float,
     *     days_overdue: int,
     *     days_until_due: int,
     *     status: string,
     *     is_partial: bool,
     *     bucket: string,
     *     source: string
     * }>
     */
    public function allocateInstallmentsFifo(array $installments, float $totalPaid, string $asOfDate): array
    {
        $asOf = Carbon::parse($asOfDate)->startOfDay();
        $asOfFormatted = $asOf->format('Y-m-d');

        $indexed = [];
        foreach (array_values($installments) as $idx => $item) {
            $source = isset($item['source']) ? (string) $item['source'] : 'due_date';
            $indexed[] = [
                'idx' => $idx,
                'id' => isset($item['id']) && $item['id'] !== null ? (int) $item['id'] : null,
                'date' => $this->normalizeDate($item['date'] ?? null) ?? $asOfFormatted,
                'amount' => Money::round(max(0.0, (float) ($item['amount'] ?? 0.0))),
                'source' => $source,
                'is_synthetic' => in_array($source, self::SYNTHETIC_SOURCES, true) ? 1 : 0,
            ];
        }

        usort($indexed, function (array $a, array $b) {
            if ($a['is_synthetic'] !== $b['is_synthetic']) {
                return $a['is_synthetic'] <=> $b['is_synthetic'];
            }
            $dateCmp = strcmp($a['date'], $b['date']);
            if ($dateCmp !== 0) {
                return $dateCmp;
            }
            $idA = $a['id'] ?? PHP_INT_MAX;
            $idB = $b['id'] ?? PHP_INT_MAX;
            if ($idA !== $idB) {
                return $idA <=> $idB;
            }

            return $a['idx'] <=> $b['idx'];
        });

        $remainingPaid = Money::round(max(0.0, $totalPaid));
        $result = [];

        foreach ($indexed as $inst) {
            $amount = $inst['amount'];
            $paidAmount = Money::round(min($amount, $remainingPaid));
            $remainingPaid = Money::round(max(0.0, $remainingPaid - $paidAmount));
            $pendingAmount = Money::round(max(0.0, $amount - $paidAmount));

            $dueCarbon = Carbon::parse($inst['date'])->startOfDay();
            // Positive when $asOf > $dueCarbon (overdue); <= 0 when not yet due.
            $signedDays = (int) $dueCarbon->diffInDays($asOf, false);
            $daysOverdue = $pendingAmount <= 0.0 ? 0 : $signedDays;
            $daysUntilDue = $pendingAmount <= 0.0 ? 0 : -$signedDays;
            $isPartial = ($paidAmount > 0.0 && $pendingAmount > 0.0);

            if ($pendingAmount <= 0.0) {
                $status = 'paid';
            } elseif ($daysOverdue > 0) {
                $status = 'overdue';
            } elseif ($daysUntilDue <= self::DUE_SOON_DAYS) {
                $status = 'due_soon';
            } elseif ($isPartial) {
                $status = 'partial';
            } else {
                $status = 'pending';
            }

            $bucket = $this->resolveAgingBucket($daysOverdue);

            $result[] = [
                'id' => $inst['id'],
                'date' => $inst['date'],
                'amount' => $amount,
                'paid_amount' => $paidAmount,
                'pending_amount' => $pendingAmount,
                'days_overdue' => $daysOverdue,
                'days_until_due' => $daysUntilDue,
                'status' => $status,
                'is_partial' => $isPartial,
                'bucket' => $bucket,
                'source' => $inst['source'],
            ];
        }

        return $result;
    }

    /**
     * Returns accounts receivable schedule (documents + sale notes) with FIFO installments,
     * RS 193-2020 detraction/retention metadata, and bancarization status.
     */
    public function getReceivables(array $filters = [], ?string $asOfDate = null): array
    {
        $this->resetCaches();
        $asOf = $this->resolveAsOfDate($asOfDate);
        $fallbackUsdSaleRate = $this->resolveLatestExchangeRateSale($asOf);

        $isAdmin = array_key_exists('is_admin', $filters) ? (bool) $filters['is_admin'] : true;
        $userId = isset($filters['user_id']) && $filters['user_id'] !== '' ? (int) $filters['user_id'] : null;
        $establishmentId = isset($filters['establishment_id']) && $filters['establishment_id'] !== ''
            ? (int) $filters['establishment_id']
            : null;
        $currencyTypeId = !empty($filters['currency_type_id']) ? strtoupper(trim((string) $filters['currency_type_id'])) : null;
        $customerId = isset($filters['customer_id']) && $filters['customer_id'] !== '' ? (int) $filters['customer_id'] : null;
        $dateStart = !empty($filters['date_start']) ? (string) $filters['date_start'] : null;
        $dateEnd = !empty($filters['date_end']) ? (string) $filters['date_end'] : null;
        $statusFilter = !empty($filters['status']) ? strtolower(trim((string) $filters['status'])) : 'all';
        $search = isset($filters['search']) ? mb_strtolower(trim((string) $filters['search'])) : '';
        $includeSaleNotes = array_key_exists('include_sale_notes', $filters) ? (bool) $filters['include_sale_notes'] : true;

        if (!$isAdmin && $userId === null) {
            return $this->emptySchedulePayload($asOf);
        }

        $documents = $this->loadReceivableDocuments($isAdmin, $userId, $establishmentId, $currencyTypeId, $customerId, $dateStart, $dateEnd);
        $saleNotes = $includeSaleNotes
            ? $this->loadReceivableSaleNotes($isAdmin, $userId, $establishmentId, $currencyTypeId, $customerId, $dateStart, $dateEnd)
            : [];

        $docIds = array_map(fn ($row) => (int) $row->id, $documents);
        $snIds = array_map(fn ($row) => (int) $row->id, $saleNotes);

        $personIds = [];
        foreach ($documents as $d) {
            if (!empty($d->customer_id)) {
                $personIds[(int) $d->customer_id] = true;
            }
        }
        foreach ($saleNotes as $s) {
            if (!empty($s->customer_id)) {
                $personIds[(int) $s->customer_id] = true;
            }
        }

        $persons = $this->loadPersonsMap(array_keys($personIds));
        $docPaymentsByDoc = $this->loadPaymentsGrouped('document_payments', 'document_id', $docIds);
        $snPaymentsByNote = $this->loadPaymentsGrouped('sale_note_payments', 'sale_note_id', $snIds);
        $docFeesByDoc = $this->loadDocumentFeesGrouped($docIds);
        $invoiceDueByDoc = $this->loadInvoiceDueDates($docIds);
        $receivedRetentionsByDoc = $this->loadReceivedRetentionsGrouped($docIds);

        $allDocPaymentIds = $this->flattenPaymentIds($docPaymentsByDoc);
        $allSnPaymentIds = $this->flattenPaymentIds($snPaymentsByNote);
        $evidences = $this->loadPaymentEvidencesMap([
            DocumentPayment::class => $allDocPaymentIds,
            SaleNotePayment::class => $allSnPaymentIds,
        ]);

        $rows = [];

        foreach ($documents as $doc) {
            $docId = (int) $doc->id;
            $payments = $docPaymentsByDoc[$docId] ?? [];
            $totalPaid = $this->sumPayments($payments);
            $total = Money::round((float) $doc->total);
            $balance = Money::round($total - $totalPaid);

            if ($balance <= 0.0) {
                continue;
            }

            $person = $persons[(int) $doc->customer_id] ?? null;
            $numberFull = trim((string) $doc->series) . '-' . trim((string) $doc->number);
            if ($search !== '' && !$this->matchesSearch($search, $numberFull, $person)) {
                continue;
            }

            $currency = strtoupper(trim((string) ($doc->currency_type_id ?: 'PEN')));
            $exchangeRateSale = (isset($doc->exchange_rate_sale) && (float) $doc->exchange_rate_sale > 0)
                ? (float) $doc->exchange_rate_sale
                : null;
            $dateOfIssue = $this->normalizeDate($doc->date_of_issue) ?? $asOf;
            $invoiceDueDate = $invoiceDueByDoc[$docId] ?? null;

            $detractionPen = $this->extractDetractionPen($doc->detraction ?? null);
            $detractionInCurrency = $this->convertPenToCurrency($detractionPen, $currency, $exchangeRateSale);
            $retentionAmount = $this->extractRetentionAmount($doc->discounts ?? null);
            // Phase 4: a CRE registered in Treasury (retention_received) is the customer's proof of retention.
            // The invoice may already declare the same retention (discount code 62), so the effective amount is
            // the larger of both, never their sum (that would count the same retention twice).
            $registeredRetention = Money::round((float) ($receivedRetentionsByDoc[$docId] ?? 0.0));
            $effectiveRetention = max($retentionAmount, $registeredRetention);

            $rawFees = $docFeesByDoc[$docId] ?? [];
            $hasCustomSchedule = !empty($rawFees);
            if ($hasCustomSchedule) {
                $scheduledSum = Money::round(array_sum(array_map(fn ($f) => (float) $f['amount'], $rawFees)));
                $netCreditAmount = $scheduledSum;
                $scheduleInput = $rawFees;

                if ($scheduledSum < Money::round($total - 0.005)) {
                    $lastFeeDate = end($rawFees)['date'] ?? null;
                    $residualAmount = Money::round($total - $scheduledSum);
                    $scheduleInput[] = [
                        'id' => null,
                        'date' => $invoiceDueDate ?? $lastFeeDate ?? $dateOfIssue,
                        'amount' => $residualAmount,
                        'source' => ($detractionInCurrency + $effectiveRetention > 0.0)
                            ? 'detraction_retention'
                            : 'unscheduled_balance',
                    ];
                }

                $installments = $this->allocateInstallmentsFifo($scheduleInput, $totalPaid, $asOf);
            } else {
                $netCreditAmount = Money::round(max(0.0, $total - $detractionInCurrency - $effectiveRetention));
                $installments = $this->allocateInstallmentsFifo([
                    [
                        'id' => null,
                        'date' => $invoiceDueDate ?? $dateOfIssue,
                        'amount' => $total,
                        'source' => 'due_date',
                    ],
                ], $totalPaid, $asOf);
            }

            $rowStatus = $this->resolveRowStatus($installments);
            if ($statusFilter !== 'all' && $rowStatus !== $statusFilter) {
                continue;
            }

            $bancarization = $this->evaluateObligationBancarization(
                $total,
                $currency,
                $dateOfIssue,
                $exchangeRateSale,
                DocumentPayment::class,
                $payments,
                $evidences
            );

            $effectiveRate = $this->resolveEffectiveExchangeRate($currency, $exchangeRateSale, $fallbackUsdSaleRate);
            $balancePen = $effectiveRate !== null ? Money::round($balance * $effectiveRate) : null;

            $rows[] = [
                'id' => $docId,
                'type' => 'document',
                'document_type_id' => (string) ($doc->document_type_id ?? ''),
                'number_full' => $numberFull,
                'date_of_issue' => $dateOfIssue,
                'date_of_due' => $this->resolveEffectiveDueDate($installments, $invoiceDueDate ?? $dateOfIssue),
                'establishment_id' => (int) ($doc->establishment_id ?? 0),
                'user_id' => (int) ($doc->user_id ?? 0),
                'customer_id' => (int) ($doc->customer_id ?? 0),
                'customer_name' => $person['name'] ?? 'Sin cliente',
                'customer_number' => $person['number'] ?? null,
                'currency_type_id' => $currency,
                'exchange_rate_sale' => $exchangeRateSale,
                'total' => $total,
                'total_paid' => $totalPaid,
                'balance' => $balance,
                'balance_pen' => $balancePen,
                'detraction_amount' => $detractionInCurrency,
                'detraction_amount_pen' => $detractionPen,
                'retention_amount' => $retentionAmount,
                'retention_registered_amount' => $registeredRetention,
                'net_credit_amount' => $netCreditAmount,
                // Explicit net receivable (Phase 4): what the customer still owes ME in my normal accounts =
                // gross balance (parity with UnpaidController) - detraction (deposited by the customer in the BN
                // account, tracked in Detracciones) - retention (certificate received).
                'net_receivable_amount' => Money::round(max(0.0, $balance - $detractionInCurrency - $effectiveRetention)),
                'has_installments' => $hasCustomSchedule,
                'installments_count' => count($installments),
                'installments' => $installments,
                'status' => $rowStatus,
                'max_days_overdue' => $this->resolveMaxDaysOverdue($installments),
                'min_days_until_due' => $this->resolveMinDaysUntilDue($installments),
                'last_payment_date' => $this->resolveLastPaymentDate($payments),
                'payments_count' => $bancarization['payments_count'],
                'bancarization_threshold' => $bancarization['threshold'],
                'obligation_exceeds_bancarization' => $bancarization['exceeds'],
                'payments_without_evidence_count' => $bancarization['payments_without_evidence_count'],
                'missing_payment_evidence' => $bancarization['missing_payment_evidence'],
                'pending_requires_bancarization' => $bancarization['pending_requires_bancarization'],
                'missing_bancarization_evidence' => $bancarization['missing_evidence'],
                'bancarization_alert_reason' => $bancarization['alert_reason'],
                'payment_endpoint_type' => 'document',
            ];
        }

        foreach ($saleNotes as $sn) {
            $snId = (int) $sn->id;
            $payments = $snPaymentsByNote[$snId] ?? [];
            $totalPaid = $this->sumPayments($payments);
            $total = Money::round((float) $sn->total);
            $balance = Money::round($total - $totalPaid);

            if ($balance <= 0.0) {
                continue;
            }

            $person = $persons[(int) $sn->customer_id] ?? null;
            $numberFull = $this->resolveSaleNoteNumber($sn);
            if ($search !== '' && !$this->matchesSearch($search, $numberFull, $person)) {
                continue;
            }

            $currency = strtoupper(trim((string) ($sn->currency_type_id ?: 'PEN')));
            $exchangeRateSale = (isset($sn->exchange_rate_sale) && (float) $sn->exchange_rate_sale > 0)
                ? (float) $sn->exchange_rate_sale
                : null;
            $dateOfIssue = $this->normalizeDate($sn->date_of_issue) ?? $asOf;

            $installments = $this->allocateInstallmentsFifo([
                [
                    'id' => null,
                    'date' => $dateOfIssue,
                    'amount' => $total,
                    'source' => 'due_date',
                ],
            ], $totalPaid, $asOf);

            $rowStatus = $this->resolveRowStatus($installments);
            if ($statusFilter !== 'all' && $rowStatus !== $statusFilter) {
                continue;
            }

            $bancarization = $this->evaluateObligationBancarization(
                $total,
                $currency,
                $dateOfIssue,
                $exchangeRateSale,
                SaleNotePayment::class,
                $payments,
                $evidences
            );

            $effectiveRate = $this->resolveEffectiveExchangeRate($currency, $exchangeRateSale, $fallbackUsdSaleRate);
            $balancePen = $effectiveRate !== null ? Money::round($balance * $effectiveRate) : null;

            $rows[] = [
                'id' => $snId,
                'type' => 'sale_note',
                'document_type_id' => null,
                'number_full' => $numberFull,
                'date_of_issue' => $dateOfIssue,
                'date_of_due' => $dateOfIssue,
                'establishment_id' => (int) ($sn->establishment_id ?? 0),
                'user_id' => (int) ($sn->user_id ?? 0),
                'customer_id' => (int) ($sn->customer_id ?? 0),
                'customer_name' => $person['name'] ?? 'Sin cliente',
                'customer_number' => $person['number'] ?? null,
                'currency_type_id' => $currency,
                'exchange_rate_sale' => $exchangeRateSale,
                'total' => $total,
                'total_paid' => $totalPaid,
                'balance' => $balance,
                'balance_pen' => $balancePen,
                'detraction_amount' => 0.0,
                'detraction_amount_pen' => 0.0,
                'retention_amount' => 0.0,
                'retention_registered_amount' => 0.0,
                'net_credit_amount' => $total,
                'net_receivable_amount' => $balance,
                'has_installments' => false,
                'installments_count' => count($installments),
                'installments' => $installments,
                'status' => $rowStatus,
                'max_days_overdue' => $this->resolveMaxDaysOverdue($installments),
                'min_days_until_due' => $this->resolveMinDaysUntilDue($installments),
                'last_payment_date' => $this->resolveLastPaymentDate($payments),
                'payments_count' => $bancarization['payments_count'],
                'bancarization_threshold' => $bancarization['threshold'],
                'obligation_exceeds_bancarization' => $bancarization['exceeds'],
                'payments_without_evidence_count' => $bancarization['payments_without_evidence_count'],
                'missing_payment_evidence' => $bancarization['missing_payment_evidence'],
                'pending_requires_bancarization' => $bancarization['pending_requires_bancarization'],
                'missing_bancarization_evidence' => $bancarization['missing_evidence'],
                'bancarization_alert_reason' => $bancarization['alert_reason'],
                'payment_endpoint_type' => 'sale_note',
            ];
        }

        $this->sortScheduleRows($rows);

        return [
            'as_of_date' => $asOf,
            'rows' => $rows,
            'totals' => $this->buildScheduleTotals($rows),
        ];
    }

    /**
     * Returns accounts payable schedule (purchases + expenses) with FIFO installments
     * (`purchase_fees` when configured, else `date_of_due`/`date_of_issue`) and bancarization status.
     */
    public function getPayables(array $filters = [], ?string $asOfDate = null): array
    {
        $this->resetCaches();
        $asOf = $this->resolveAsOfDate($asOfDate);
        $fallbackUsdSaleRate = $this->resolveLatestExchangeRateSale($asOf);

        $isAdmin = array_key_exists('is_admin', $filters) ? (bool) $filters['is_admin'] : true;
        $userId = isset($filters['user_id']) && $filters['user_id'] !== '' ? (int) $filters['user_id'] : null;
        $establishmentId = isset($filters['establishment_id']) && $filters['establishment_id'] !== ''
            ? (int) $filters['establishment_id']
            : null;
        $currencyTypeId = !empty($filters['currency_type_id']) ? strtoupper(trim((string) $filters['currency_type_id'])) : null;
        $supplierId = isset($filters['supplier_id']) && $filters['supplier_id'] !== '' ? (int) $filters['supplier_id'] : null;
        $dateStart = !empty($filters['date_start']) ? (string) $filters['date_start'] : null;
        $dateEnd = !empty($filters['date_end']) ? (string) $filters['date_end'] : null;
        $statusFilter = !empty($filters['status']) ? strtolower(trim((string) $filters['status'])) : 'all';
        $search = isset($filters['search']) ? mb_strtolower(trim((string) $filters['search'])) : '';
        $includeExpenses = array_key_exists('include_expenses', $filters) ? (bool) $filters['include_expenses'] : true;

        if (!$isAdmin && $userId === null) {
            return $this->emptySchedulePayload($asOf);
        }

        $purchases = $this->loadPayablePurchases($isAdmin, $userId, $establishmentId, $currencyTypeId, $supplierId, $dateStart, $dateEnd);
        $expenses = $includeExpenses
            ? $this->loadPayableExpenses($isAdmin, $userId, $establishmentId, $currencyTypeId, $supplierId, $dateStart, $dateEnd)
            : [];

        $purchaseIds = array_map(fn ($row) => (int) $row->id, $purchases);
        $expenseIds = array_map(fn ($row) => (int) $row->id, $expenses);

        $personIds = [];
        foreach ($purchases as $p) {
            if (!empty($p->supplier_id)) {
                $personIds[(int) $p->supplier_id] = true;
            }
        }
        foreach ($expenses as $e) {
            if (!empty($e->supplier_id)) {
                $personIds[(int) $e->supplier_id] = true;
            }
        }

        $persons = $this->loadPersonsMap(array_keys($personIds));
        $purchasePaymentsById = $this->loadPaymentsGrouped('purchase_payments', 'purchase_id', $purchaseIds);
        $expensePaymentsById = $this->loadPaymentsGrouped('expense_payments', 'expense_id', $expenseIds);
        $purchaseFeesById = $this->loadPurchaseFeesGrouped($purchaseIds);

        $allPurchasePaymentIds = $this->flattenPaymentIds($purchasePaymentsById);
        $allExpensePaymentIds = $this->flattenPaymentIds($expensePaymentsById);
        $evidences = $this->loadPaymentEvidencesMap([
            PurchasePayment::class => $allPurchasePaymentIds,
            ExpensePayment::class => $allExpensePaymentIds,
        ]);

        $rows = [];

        foreach ($purchases as $purchase) {
            $purchaseId = (int) $purchase->id;
            $payments = $purchasePaymentsById[$purchaseId] ?? [];
            $totalPaid = $this->sumPayments($payments);
            $total = Money::round((float) $purchase->total);
            $balance = Money::round($total - $totalPaid);

            if ($balance <= 0.0) {
                continue;
            }

            $person = $persons[(int) $purchase->supplier_id] ?? null;
            $numberFull = trim((string) $purchase->series) . '-' . trim((string) $purchase->number);
            if ($search !== '' && !$this->matchesSearch($search, $numberFull, $person)) {
                continue;
            }

            $currency = strtoupper(trim((string) ($purchase->currency_type_id ?: 'PEN')));
            $exchangeRateSale = (isset($purchase->exchange_rate_sale) && (float) $purchase->exchange_rate_sale > 0)
                ? (float) $purchase->exchange_rate_sale
                : null;
            $dateOfIssue = $this->normalizeDate($purchase->date_of_issue) ?? $asOf;
            $dueDate = $this->normalizeDate($purchase->date_of_due ?? null) ?? $dateOfIssue;

            $rawFees = $purchaseFeesById[$purchaseId] ?? [];
            $hasCustomSchedule = !empty($rawFees);
            if ($hasCustomSchedule) {
                $scheduledSum = Money::round(array_sum(array_map(fn ($f) => (float) $f['amount'], $rawFees)));
                $scheduleInput = $rawFees;

                if ($scheduledSum < Money::round($total - 0.005)) {
                    $scheduleInput[] = [
                        'id' => null,
                        'date' => $dueDate,
                        'amount' => Money::round($total - $scheduledSum),
                        'source' => 'unscheduled_balance',
                    ];
                }

                $installments = $this->allocateInstallmentsFifo($scheduleInput, $totalPaid, $asOf);
            } else {
                $installments = $this->allocateInstallmentsFifo([
                    [
                        'id' => null,
                        'date' => $dueDate,
                        'amount' => $total,
                        'source' => 'due_date',
                    ],
                ], $totalPaid, $asOf);
            }

            $rowStatus = $this->resolveRowStatus($installments);
            if ($statusFilter !== 'all' && $rowStatus !== $statusFilter) {
                continue;
            }

            $bancarization = $this->evaluateObligationBancarization(
                $total,
                $currency,
                $dateOfIssue,
                $exchangeRateSale,
                PurchasePayment::class,
                $payments,
                $evidences
            );

            $effectiveRate = $this->resolveEffectiveExchangeRate($currency, $exchangeRateSale, $fallbackUsdSaleRate);
            $balancePen = $effectiveRate !== null ? Money::round($balance * $effectiveRate) : null;

            $purchaseDetractionPen = $this->extractDetractionPen($purchase->detraction ?? null);
            $purchaseDetraction = $this->convertPenToCurrency($purchaseDetractionPen, $currency, $exchangeRateSale);

            $rows[] = [
                'id' => $purchaseId,
                'type' => 'purchase',
                'document_type_id' => (string) ($purchase->document_type_id ?? ''),
                'number_full' => $numberFull,
                'date_of_issue' => $dateOfIssue,
                'date_of_due' => $this->resolveEffectiveDueDate($installments, $dueDate),
                'establishment_id' => (int) ($purchase->establishment_id ?? 0),
                'user_id' => (int) ($purchase->user_id ?? 0),
                'supplier_id' => (int) ($purchase->supplier_id ?? 0),
                'supplier_name' => $person['name'] ?? 'Sin proveedor',
                'supplier_number' => $person['number'] ?? null,
                'currency_type_id' => $currency,
                'exchange_rate_sale' => $exchangeRateSale,
                'total' => $total,
                'total_paid' => $totalPaid,
                'balance' => $balance,
                'balance_pen' => $balancePen,
                'total_canceled' => isset($purchase->total_canceled) ? (bool) $purchase->total_canceled : false,
                'detraction_amount' => $purchaseDetraction,
                'detraction_amount_pen' => $purchaseDetractionPen,
                // Explicit net payable (Phase 4): what I still pay the SUPPLIER = gross balance (parity) - the
                // detraction I deposit in the supplier's BN account (tracked in Detracciones).
                'net_payable_amount' => Money::round(max(0.0, $balance - $purchaseDetraction)),
                'has_installments' => $hasCustomSchedule,
                'installments_count' => count($installments),
                'installments' => $installments,
                'status' => $rowStatus,
                'max_days_overdue' => $this->resolveMaxDaysOverdue($installments),
                'min_days_until_due' => $this->resolveMinDaysUntilDue($installments),
                'last_payment_date' => $this->resolveLastPaymentDate($payments),
                'payments_count' => $bancarization['payments_count'],
                'bancarization_threshold' => $bancarization['threshold'],
                'obligation_exceeds_bancarization' => $bancarization['exceeds'],
                'payments_without_evidence_count' => $bancarization['payments_without_evidence_count'],
                'missing_payment_evidence' => $bancarization['missing_payment_evidence'],
                'pending_requires_bancarization' => $bancarization['pending_requires_bancarization'],
                'missing_bancarization_evidence' => $bancarization['missing_evidence'],
                'bancarization_alert_reason' => $bancarization['alert_reason'],
                'payment_endpoint_type' => 'purchase',
            ];
        }

        foreach ($expenses as $expense) {
            $expenseId = (int) $expense->id;
            $payments = $expensePaymentsById[$expenseId] ?? [];
            $totalPaid = $this->sumPayments($payments);
            $total = Money::round((float) $expense->total);
            $balance = Money::round($total - $totalPaid);

            if ($balance <= 0.0) {
                continue;
            }

            $person = $persons[(int) $expense->supplier_id] ?? null;
            $numberFull = trim((string) ($expense->number ?? ('GASTO-' . $expenseId)));
            if ($search !== '' && !$this->matchesSearch($search, $numberFull, $person)) {
                continue;
            }

            $currency = strtoupper(trim((string) ($expense->currency_type_id ?: 'PEN')));
            $exchangeRateSale = (isset($expense->exchange_rate_sale) && (float) $expense->exchange_rate_sale > 0)
                ? (float) $expense->exchange_rate_sale
                : null;
            $dateOfIssue = $this->normalizeDate($expense->date_of_issue) ?? $asOf;

            $installments = $this->allocateInstallmentsFifo([
                [
                    'id' => null,
                    'date' => $dateOfIssue,
                    'amount' => $total,
                    'source' => 'due_date',
                ],
            ], $totalPaid, $asOf);

            $rowStatus = $this->resolveRowStatus($installments);
            if ($statusFilter !== 'all' && $rowStatus !== $statusFilter) {
                continue;
            }

            $bancarization = $this->evaluateObligationBancarization(
                $total,
                $currency,
                $dateOfIssue,
                $exchangeRateSale,
                ExpensePayment::class,
                $payments,
                $evidences
            );

            $effectiveRate = $this->resolveEffectiveExchangeRate($currency, $exchangeRateSale, $fallbackUsdSaleRate);
            $balancePen = $effectiveRate !== null ? Money::round($balance * $effectiveRate) : null;

            $rows[] = [
                'id' => $expenseId,
                'type' => 'expense',
                'document_type_id' => null,
                'number_full' => $numberFull,
                'date_of_issue' => $dateOfIssue,
                'date_of_due' => $dateOfIssue,
                'establishment_id' => (int) ($expense->establishment_id ?? 0),
                'user_id' => (int) ($expense->user_id ?? 0),
                'supplier_id' => (int) ($expense->supplier_id ?? 0),
                'supplier_name' => $person['name'] ?? 'Sin proveedor',
                'supplier_number' => $person['number'] ?? null,
                'currency_type_id' => $currency,
                'exchange_rate_sale' => $exchangeRateSale,
                'total' => $total,
                'total_paid' => $totalPaid,
                'balance' => $balance,
                'balance_pen' => $balancePen,
                'total_canceled' => false,
                'has_installments' => false,
                'installments_count' => count($installments),
                'installments' => $installments,
                'status' => $rowStatus,
                'max_days_overdue' => $this->resolveMaxDaysOverdue($installments),
                'min_days_until_due' => $this->resolveMinDaysUntilDue($installments),
                'last_payment_date' => $this->resolveLastPaymentDate($payments),
                'payments_count' => $bancarization['payments_count'],
                'bancarization_threshold' => $bancarization['threshold'],
                'obligation_exceeds_bancarization' => $bancarization['exceeds'],
                'payments_without_evidence_count' => $bancarization['payments_without_evidence_count'],
                'missing_payment_evidence' => $bancarization['missing_payment_evidence'],
                'pending_requires_bancarization' => $bancarization['pending_requires_bancarization'],
                'missing_bancarization_evidence' => $bancarization['missing_evidence'],
                'bancarization_alert_reason' => $bancarization['alert_reason'],
                'payment_endpoint_type' => 'expense',
            ];
        }

        $this->sortScheduleRows($rows);

        return [
            'as_of_date' => $asOf,
            'rows' => $rows,
            'totals' => $this->buildScheduleTotals($rows),
        ];
    }

    /**
     * Builds installment-level aging buckets grouped by (person_id, currency_type_id)
     * plus consolidated PEN totals.
     */
    public function getAging(string $side, array $filters = [], ?string $asOfDate = null): array
    {
        $normalizedSide = strtolower(trim($side)) === 'payables' ? 'payables' : 'receivables';
        $schedule = $normalizedSide === 'payables'
            ? $this->getPayables($filters, $asOfDate)
            : $this->getReceivables($filters, $asOfDate);

        return $this->buildAgingFromSchedule($normalizedSide, $schedule, $schedule['as_of_date']);
    }

    /**
     * Builds Phase 3 alerts across AR and AP: overdue installments, installments due in 0..7 days,
     * and obligations exceeding the bancarization threshold lacking valid evidence.
     */
    public function getAlerts(array $filters = [], ?string $asOfDate = null): array
    {
        $receivables = $this->getReceivables($filters, $asOfDate);
        $payables = $this->getPayables($filters, $asOfDate);

        return $this->buildAlertsFromSchedules($receivables, $payables, $receivables['as_of_date']);
    }

    /**
     * Single-pass combined payload for the Receivables & Payables screen so AR/AP queries run only once.
     */
    public function getOverview(array $filters = [], ?string $asOfDate = null): array
    {
        $asOf = $this->resolveAsOfDate($asOfDate);
        $receivables = $this->getReceivables($filters, $asOf);
        $payables = $this->getPayables($filters, $asOf);

        return [
            'as_of_date' => $asOf,
            'receivables' => $receivables,
            'payables' => $payables,
            'aging_receivables' => $this->buildAgingFromSchedule('receivables', $receivables, $asOf),
            'aging_payables' => $this->buildAgingFromSchedule('payables', $payables, $asOf),
            'alerts' => $this->buildAlertsFromSchedules($receivables, $payables, $asOf),
        ];
    }

    private function buildAgingFromSchedule(string $side, array $schedule, string $asOf): array
    {
        $fallbackUsdSaleRate = $this->resolveLatestExchangeRateSale($asOf);
        $personIdKey = $side === 'payables' ? 'supplier_id' : 'customer_id';
        $personNameKey = $side === 'payables' ? 'supplier_name' : 'customer_name';
        $personNumberKey = $side === 'payables' ? 'supplier_number' : 'customer_number';

        $groups = [];
        $totalsByCurrency = [];
        $grandPen = $this->emptyBucketTotals();
        $missingExchangeRate = false;

        foreach ($schedule['rows'] as $row) {
            $personId = (int) ($row[$personIdKey] ?? 0);
            $currency = (string) ($row['currency_type_id'] ?? 'PEN');
            $groupKey = $personId . ':' . $currency;

            $effectiveRate = $this->resolveEffectiveExchangeRate(
                $currency,
                ($row['exchange_rate_sale'] !== null && $row['exchange_rate_sale'] > 0) ? (float) $row['exchange_rate_sale'] : null,
                $fallbackUsdSaleRate
            );

            foreach ($row['installments'] as $inst) {
                $pending = Money::round((float) ($inst['pending_amount'] ?? 0.0));
                if ($pending <= 0.0) {
                    continue;
                }

                $bucket = $inst['bucket'];
                if (!isset($groups[$groupKey])) {
                    $groups[$groupKey] = array_merge([
                        'person_id' => $personId,
                        'person_name' => $row[$personNameKey] ?? 'Sin registro',
                        'person_number' => $row[$personNumberKey] ?? null,
                        'currency_type_id' => $currency,
                        'documents_count' => 0,
                        'document_keys' => [],
                    ], $this->emptyBucketTotals(), [
                        'total_pen' => 0.0,
                        'has_missing_rate' => false,
                    ]);
                }

                $docMarker = $row['type'] . ':' . $row['id'];
                if (!isset($groups[$groupKey]['document_keys'][$docMarker])) {
                    $groups[$groupKey]['document_keys'][$docMarker] = true;
                    $groups[$groupKey]['documents_count']++;
                }

                $groups[$groupKey][$bucket] = Money::round($groups[$groupKey][$bucket] + $pending);
                $groups[$groupKey]['total'] = Money::round($groups[$groupKey]['total'] + $pending);

                if (!isset($totalsByCurrency[$currency])) {
                    $totalsByCurrency[$currency] = array_merge(
                        ['currency_type_id' => $currency],
                        $this->emptyBucketTotals()
                    );
                }
                $totalsByCurrency[$currency][$bucket] = Money::round($totalsByCurrency[$currency][$bucket] + $pending);
                $totalsByCurrency[$currency]['total'] = Money::round($totalsByCurrency[$currency]['total'] + $pending);

                if ($effectiveRate !== null) {
                    $pendingPen = Money::round($pending * $effectiveRate);
                    $groups[$groupKey]['total_pen'] = Money::round($groups[$groupKey]['total_pen'] + $pendingPen);
                    $grandPen[$bucket] = Money::round($grandPen[$bucket] + $pendingPen);
                    $grandPen['total'] = Money::round($grandPen['total'] + $pendingPen);
                } else {
                    $groups[$groupKey]['has_missing_rate'] = true;
                    $missingExchangeRate = true;
                }
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            unset($group['document_keys']);
            if ($group['has_missing_rate']) {
                $group['total_pen'] = null;
            }
            unset($group['has_missing_rate']);
            $rows[] = $group;
        }

        usort($rows, function (array $a, array $b) {
            $penA = $a['total_pen'] ?? $a['total'];
            $penB = $b['total_pen'] ?? $b['total'];
            if ($penA !== $penB) {
                return $penB <=> $penA;
            }

            return strcmp($a['person_name'], $b['person_name']);
        });

        ksort($totalsByCurrency);

        return [
            'side' => $side,
            'as_of_date' => $asOf,
            'rows' => $rows,
            'totals_by_currency' => array_values($totalsByCurrency),
            'totals_pen' => $grandPen,
            'missing_exchange_rate' => $missingExchangeRate,
        ];
    }

    private function buildAlertsFromSchedules(array $receivables, array $payables, string $asOf): array
    {
        $overdueInstallments = [];
        $dueSoonInstallments = [];
        $unbancarizedObligations = [];
        $unbancarizedPaymentsCount = 0;
        $unbancarizedPendingCount = 0;

        $sides = [
            'receivable' => $receivables['rows'],
            'payable' => $payables['rows'],
        ];

        foreach ($sides as $side => $rows) {
            foreach ($rows as $row) {
                $counterpartyName = $side === 'receivable' ? $row['customer_name'] : $row['supplier_name'];
                $counterpartyNumber = $side === 'receivable' ? $row['customer_number'] : $row['supplier_number'];

                foreach ($row['installments'] as $idx => $inst) {
                    if ($inst['pending_amount'] <= 0.0) {
                        continue;
                    }

                    $entry = [
                        'side' => $side,
                        'type' => $row['type'],
                        'record_id' => $row['id'],
                        'number_full' => $row['number_full'],
                        'counterparty_name' => $counterpartyName,
                        'counterparty_number' => $counterpartyNumber,
                        'currency_type_id' => $row['currency_type_id'],
                        'installment_id' => $inst['id'],
                        'installment_index' => $idx + 1,
                        'source' => $inst['source'],
                        'due_date' => $inst['date'],
                        'pending_amount' => $inst['pending_amount'],
                        'days_overdue' => $inst['days_overdue'],
                        'days_until_due' => $inst['days_until_due'],
                        'payment_endpoint_type' => $row['payment_endpoint_type'],
                    ];

                    if ($inst['status'] === 'overdue') {
                        $overdueInstallments[] = $entry;
                    } elseif ($inst['status'] === 'due_soon') {
                        $dueSoonInstallments[] = $entry;
                    }
                }

                if (!empty($row['missing_bancarization_evidence'])) {
                    if (!empty($row['missing_payment_evidence'])) {
                        $unbancarizedPaymentsCount++;
                    } elseif (!empty($row['pending_requires_bancarization'])) {
                        $unbancarizedPendingCount++;
                    }

                    $unbancarizedObligations[] = [
                        'side' => $side,
                        'type' => $row['type'],
                        'record_id' => $row['id'],
                        'number_full' => $row['number_full'],
                        'counterparty_name' => $counterpartyName,
                        'counterparty_number' => $counterpartyNumber,
                        'currency_type_id' => $row['currency_type_id'],
                        'total' => $row['total'],
                        'balance' => $row['balance'],
                        'bancarization_threshold' => $row['bancarization_threshold'],
                        'payments_count' => $row['payments_count'],
                        'payments_without_evidence_count' => $row['payments_without_evidence_count'],
                        'missing_payment_evidence' => (bool) ($row['missing_payment_evidence'] ?? false),
                        'pending_requires_bancarization' => (bool) ($row['pending_requires_bancarization'] ?? false),
                        'reason' => $row['bancarization_alert_reason'],
                        'payment_endpoint_type' => $row['payment_endpoint_type'],
                    ];
                }
            }
        }

        usort($overdueInstallments, fn ($a, $b) => ($b['days_overdue'] <=> $a['days_overdue']) ?: strcmp($a['due_date'], $b['due_date']));
        usort($dueSoonInstallments, fn ($a, $b) => ($a['days_until_due'] <=> $b['days_until_due']) ?: strcmp($a['due_date'], $b['due_date']));
        usort($unbancarizedObligations, fn ($a, $b) => ((int) $b['missing_payment_evidence'] <=> (int) $a['missing_payment_evidence']) ?: strcmp($a['number_full'], $b['number_full']));

        return [
            'as_of_date' => $asOf,
            'overdue_installments' => [
                'count' => count($overdueInstallments),
                'totals_by_currency' => $this->sumAlertsByCurrency($overdueInstallments, 'pending_amount'),
                'items' => $overdueInstallments,
            ],
            'due_soon_installments' => [
                'count' => count($dueSoonInstallments),
                'totals_by_currency' => $this->sumAlertsByCurrency($dueSoonInstallments, 'pending_amount'),
                'items' => $dueSoonInstallments,
            ],
            'unbancarized_obligations' => [
                'count' => count($unbancarizedObligations),
                'payments_missing_count' => $unbancarizedPaymentsCount,
                'pending_reminder_count' => $unbancarizedPendingCount,
                'items' => $unbancarizedObligations,
            ],
            'summary' => [
                'overdue_count' => count($overdueInstallments),
                'due_soon_count' => count($dueSoonInstallments),
                'unbancarized_payments_count' => $unbancarizedPaymentsCount,
                'unbancarized_pending_count' => $unbancarizedPendingCount,
                'unbancarized_count' => count($unbancarizedObligations),
            ],
        ];
    }

    private function loadReceivableDocuments(
        bool $isAdmin,
        ?int $userId,
        ?int $establishmentId,
        ?string $currencyTypeId,
        ?int $customerId,
        ?string $dateStart,
        ?string $dateEnd
    ): array {
        if (!$this->hasTable('documents')) {
            return [];
        }

        $columns = [
            'documents.id',
            'documents.user_id',
            'documents.customer_id',
            'documents.establishment_id',
            'documents.state_type_id',
            'documents.document_type_id',
            'documents.series',
            'documents.number',
            'documents.date_of_issue',
            'documents.total',
            'documents.currency_type_id',
            'documents.exchange_rate_sale',
        ];
        if ($this->hasColumn('documents', 'detraction')) {
            $columns[] = 'documents.detraction';
        }
        if ($this->hasColumn('documents', 'discounts')) {
            $columns[] = 'documents.discounts';
        }

        $query = DB::connection('tenant')
            ->table('documents')
            ->select($columns)
            ->whereIn('documents.state_type_id', self::ACCEPTED_STATE_TYPES)
            ->whereIn('documents.document_type_id', self::RECEIVABLE_DOCUMENT_TYPES);

        if ($this->hasTable('document_payments')) {
            $paySub = DB::connection('tenant')
                ->table('document_payments')
                ->select('document_id', DB::raw('SUM(payment) as total_paid'))
                ->groupBy('document_id');

            $query->leftJoinSub($paySub, 'pay', function ($join) {
                $join->on('documents.id', '=', 'pay.document_id');
            })->whereRaw('(documents.total - COALESCE(pay.total_paid, 0)) > 0.005');
        } else {
            $query->where('documents.total', '>', 0.005);
        }

        if (!$isAdmin) {
            $query->where('documents.user_id', $userId);
            if ($establishmentId !== null) {
                $query->where('documents.establishment_id', $establishmentId);
            }
        } else {
            if ($userId !== null) {
                $query->where('documents.user_id', $userId);
            }
            if ($establishmentId !== null) {
                $query->where('documents.establishment_id', $establishmentId);
            }
        }

        if ($currencyTypeId !== null) {
            $query->where('documents.currency_type_id', $currencyTypeId);
        }
        if ($customerId !== null) {
            $query->where('documents.customer_id', $customerId);
        }
        if ($dateStart !== null) {
            $query->where('documents.date_of_issue', '>=', $dateStart);
        }
        if ($dateEnd !== null) {
            $query->where('documents.date_of_issue', '<=', $dateEnd);
        }

        return $query->orderBy('documents.date_of_issue', 'asc')->orderBy('documents.id', 'asc')->get()->all();
    }

    private function loadReceivableSaleNotes(
        bool $isAdmin,
        ?int $userId,
        ?int $establishmentId,
        ?string $currencyTypeId,
        ?int $customerId,
        ?string $dateStart,
        ?string $dateEnd
    ): array {
        if (!$this->hasTable('sale_notes')) {
            return [];
        }

        $columns = [
            'sale_notes.id',
            'sale_notes.user_id',
            'sale_notes.customer_id',
            'sale_notes.establishment_id',
            'sale_notes.state_type_id',
            'sale_notes.date_of_issue',
            'sale_notes.total',
            'sale_notes.currency_type_id',
            'sale_notes.exchange_rate_sale',
            'sale_notes.changed',
            'sale_notes.total_canceled',
        ];
        foreach (['series', 'number', 'prefix', 'filename'] as $optCol) {
            if ($this->hasColumn('sale_notes', $optCol)) {
                $columns[] = 'sale_notes.' . $optCol;
            }
        }

        $query = DB::connection('tenant')
            ->table('sale_notes')
            ->select($columns)
            ->whereIn('sale_notes.state_type_id', self::ACCEPTED_STATE_TYPES)
            ->where('sale_notes.changed', false)
            ->where('sale_notes.total_canceled', false);

        if ($this->hasTable('sale_note_payments')) {
            $paySub = DB::connection('tenant')
                ->table('sale_note_payments')
                ->select('sale_note_id', DB::raw('SUM(payment) as total_paid'))
                ->groupBy('sale_note_id');

            $query->leftJoinSub($paySub, 'pay', function ($join) {
                $join->on('sale_notes.id', '=', 'pay.sale_note_id');
            })->whereRaw('(sale_notes.total - COALESCE(pay.total_paid, 0)) > 0.005');
        } else {
            $query->where('sale_notes.total', '>', 0.005);
        }

        if (!$isAdmin) {
            $query->where('sale_notes.user_id', $userId);
            if ($establishmentId !== null) {
                $query->where('sale_notes.establishment_id', $establishmentId);
            }
        } else {
            if ($userId !== null) {
                $query->where('sale_notes.user_id', $userId);
            }
            if ($establishmentId !== null) {
                $query->where('sale_notes.establishment_id', $establishmentId);
            }
        }

        if ($currencyTypeId !== null) {
            $query->where('sale_notes.currency_type_id', $currencyTypeId);
        }
        if ($customerId !== null) {
            $query->where('sale_notes.customer_id', $customerId);
        }
        if ($dateStart !== null) {
            $query->where('sale_notes.date_of_issue', '>=', $dateStart);
        }
        if ($dateEnd !== null) {
            $query->where('sale_notes.date_of_issue', '<=', $dateEnd);
        }

        return $query->orderBy('sale_notes.date_of_issue', 'asc')->orderBy('sale_notes.id', 'asc')->get()->all();
    }

    private function loadPayablePurchases(
        bool $isAdmin,
        ?int $userId,
        ?int $establishmentId,
        ?string $currencyTypeId,
        ?int $supplierId,
        ?string $dateStart,
        ?string $dateEnd
    ): array {
        if (!$this->hasTable('purchases')) {
            return [];
        }

        $columns = [
            'purchases.id',
            'purchases.user_id',
            'purchases.supplier_id',
            'purchases.establishment_id',
            'purchases.state_type_id',
            'purchases.document_type_id',
            'purchases.series',
            'purchases.number',
            'purchases.date_of_issue',
            'purchases.date_of_due',
            'purchases.total',
            'purchases.currency_type_id',
            'purchases.exchange_rate_sale',
        ];
        if ($this->hasColumn('purchases', 'total_canceled')) {
            $columns[] = 'purchases.total_canceled';
        }
        if ($this->hasColumn('purchases', 'detraction')) {
            $columns[] = 'purchases.detraction';
        }

        $query = DB::connection('tenant')
            ->table('purchases')
            ->select($columns)
            ->whereIn('purchases.state_type_id', self::ACCEPTED_STATE_TYPES)
            ->whereIn('purchases.document_type_id', self::PAYABLE_DOCUMENT_TYPES);

        if ($this->hasTable('purchase_payments')) {
            $paySub = DB::connection('tenant')
                ->table('purchase_payments')
                ->select('purchase_id', DB::raw('SUM(payment) as total_paid'))
                ->groupBy('purchase_id');

            $query->leftJoinSub($paySub, 'pay', function ($join) {
                $join->on('purchases.id', '=', 'pay.purchase_id');
            })->whereRaw('(purchases.total - COALESCE(pay.total_paid, 0)) > 0.005');
        } else {
            $query->where('purchases.total', '>', 0.005);
        }

        if (!$isAdmin) {
            $query->where('purchases.user_id', $userId);
            if ($establishmentId !== null) {
                $query->where('purchases.establishment_id', $establishmentId);
            }
        } else {
            if ($userId !== null) {
                $query->where('purchases.user_id', $userId);
            }
            if ($establishmentId !== null) {
                $query->where('purchases.establishment_id', $establishmentId);
            }
        }

        if ($currencyTypeId !== null) {
            $query->where('purchases.currency_type_id', $currencyTypeId);
        }
        if ($supplierId !== null) {
            $query->where('purchases.supplier_id', $supplierId);
        }
        if ($dateStart !== null) {
            $query->where('purchases.date_of_issue', '>=', $dateStart);
        }
        if ($dateEnd !== null) {
            $query->where('purchases.date_of_issue', '<=', $dateEnd);
        }

        return $query->orderBy('purchases.date_of_issue', 'asc')->orderBy('purchases.id', 'asc')->get()->all();
    }

    private function loadPayableExpenses(
        bool $isAdmin,
        ?int $userId,
        ?int $establishmentId,
        ?string $currencyTypeId,
        ?int $supplierId,
        ?string $dateStart,
        ?string $dateEnd
    ): array {
        if (!$this->hasTable('expenses')) {
            return [];
        }

        $query = DB::connection('tenant')
            ->table('expenses')
            ->select([
                'expenses.id',
                'expenses.user_id',
                'expenses.supplier_id',
                'expenses.establishment_id',
                'expenses.state_type_id',
                'expenses.number',
                'expenses.date_of_issue',
                'expenses.total',
                'expenses.currency_type_id',
                'expenses.exchange_rate_sale',
            ])
            ->whereIn('expenses.state_type_id', self::ACCEPTED_STATE_TYPES);

        if ($this->hasTable('expense_payments')) {
            $paySub = DB::connection('tenant')
                ->table('expense_payments')
                ->select('expense_id', DB::raw('SUM(payment) as total_paid'))
                ->groupBy('expense_id');

            $query->leftJoinSub($paySub, 'pay', function ($join) {
                $join->on('expenses.id', '=', 'pay.expense_id');
            })->whereRaw('(expenses.total - COALESCE(pay.total_paid, 0)) > 0.005');
        } else {
            $query->where('expenses.total', '>', 0.005);
        }

        if (!$isAdmin) {
            $query->where('expenses.user_id', $userId);
            if ($establishmentId !== null) {
                $query->where('expenses.establishment_id', $establishmentId);
            }
        } else {
            if ($userId !== null) {
                $query->where('expenses.user_id', $userId);
            }
            if ($establishmentId !== null) {
                $query->where('expenses.establishment_id', $establishmentId);
            }
        }

        if ($currencyTypeId !== null) {
            $query->where('expenses.currency_type_id', $currencyTypeId);
        }
        if ($supplierId !== null) {
            $query->where('expenses.supplier_id', $supplierId);
        }
        if ($dateStart !== null) {
            $query->where('expenses.date_of_issue', '>=', $dateStart);
        }
        if ($dateEnd !== null) {
            $query->where('expenses.date_of_issue', '<=', $dateEnd);
        }

        return $query->orderBy('expenses.date_of_issue', 'asc')->orderBy('expenses.id', 'asc')->get()->all();
    }

    /**
     * @param  array<int, int>  $personIds
     * @return array<int, array{id: int, name: string, number: ?string}>
     */
    private function loadPersonsMap(array $personIds): array
    {
        if (empty($personIds) || !$this->hasTable('persons')) {
            return [];
        }

        $columns = ['id', 'name'];
        $hasNumber = $this->hasColumn('persons', 'number');
        if ($hasNumber) {
            $columns[] = 'number';
        }

        $map = [];
        foreach (array_chunk($personIds, 500) as $chunk) {
            $rows = DB::connection('tenant')
                ->table('persons')
                ->select($columns)
                ->whereIn('id', $chunk)
                ->get();

            foreach ($rows as $row) {
                $map[(int) $row->id] = [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'number' => $hasNumber ? ($row->number !== null ? (string) $row->number : null) : null,
                ];
            }
        }

        return $map;
    }

    /**
     * Batch-loads payment rows grouped by parent ID.
     *
     * @param  array<int, int>  $parentIds
     * @return array<int, array<int, array{id: int, payment: float, date_of_payment: ?string}>>
     */
    private function loadPaymentsGrouped(string $table, string $foreignKey, array $parentIds): array
    {
        if (empty($parentIds) || !$this->hasTable($table)) {
            return [];
        }

        $grouped = [];
        foreach (array_chunk($parentIds, 500) as $chunk) {
            $rows = DB::connection('tenant')
                ->table($table)
                ->select(['id', $foreignKey, 'payment', 'date_of_payment'])
                ->whereIn($foreignKey, $chunk)
                ->orderBy('date_of_payment', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            foreach ($rows as $row) {
                $pid = (int) $row->{$foreignKey};
                $grouped[$pid][] = [
                    'id' => (int) $row->id,
                    'payment' => (float) $row->payment,
                    'date_of_payment' => $this->normalizeDate($row->date_of_payment ?? null),
                ];
            }
        }

        return $grouped;
    }

    /**
     * @param  array<int, int>  $docIds
     * @return array<int, array<int, array{id: int, date: string, amount: float, source: string}>>
     */
    private function loadDocumentFeesGrouped(array $docIds): array
    {
        if (empty($docIds) || !$this->hasTable('document_fee')) {
            return [];
        }

        $grouped = [];
        foreach (array_chunk($docIds, 500) as $chunk) {
            $rows = DB::connection('tenant')
                ->table('document_fee')
                ->select(['id', 'document_id', 'date', 'amount'])
                ->whereIn('document_id', $chunk)
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            foreach ($rows as $row) {
                $grouped[(int) $row->document_id][] = [
                    'id' => (int) $row->id,
                    'date' => $this->normalizeDate($row->date) ?? date('Y-m-d'),
                    'amount' => Money::round((float) $row->amount),
                    'source' => 'document_fee',
                ];
            }
        }

        return $grouped;
    }

    /**
     * @param  array<int, int>  $purchaseIds
     * @return array<int, array<int, array{id: int, date: string, amount: float, source: string}>>
     */
    private function loadPurchaseFeesGrouped(array $purchaseIds): array
    {
        if (empty($purchaseIds) || !$this->hasTable('purchase_fees')) {
            return [];
        }

        $grouped = [];
        foreach (array_chunk($purchaseIds, 500) as $chunk) {
            $rows = DB::connection('tenant')
                ->table('purchase_fees')
                ->select(['id', 'purchase_id', 'date', 'amount'])
                ->whereIn('purchase_id', $chunk)
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            foreach ($rows as $row) {
                $grouped[(int) $row->purchase_id][] = [
                    'id' => (int) $row->id,
                    'date' => $this->normalizeDate($row->date) ?? date('Y-m-d'),
                    'amount' => Money::round((float) $row->amount),
                    'source' => 'purchase_fee',
                ];
            }
        }

        return $grouped;
    }

    /**
     * @param  array<int, int>  $docIds
     * @return array<int, ?string>
     */
    /**
     * Sum of active received CREs (treasury_withholdings.retention_received) per invoice, in the invoice currency.
     *
     * @param array<int, int> $docIds
     * @return array<int, float>
     */
    private function loadReceivedRetentionsGrouped(array $docIds): array
    {
        if (empty($docIds) || !$this->hasTable('treasury_withholdings')) {
            return [];
        }

        $rows = DB::connection('tenant')
            ->table('treasury_withholdings')
            ->select('parent_id', DB::raw('SUM(amount) as total_amount'))
            ->where('kind', 'retention_received')
            ->where('state', 'active')
            ->where('parent_type', Document::class)
            ->whereIn('parent_id', $docIds)
            ->groupBy('parent_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->parent_id] = Money::round((float) $row->total_amount);
        }

        return $map;
    }

    private function loadInvoiceDueDates(array $docIds): array
    {
        if (empty($docIds) || !$this->hasTable('invoices')) {
            return [];
        }

        $map = [];
        foreach (array_chunk($docIds, 500) as $chunk) {
            $rows = DB::connection('tenant')
                ->table('invoices')
                ->select(['document_id', 'date_of_due'])
                ->whereIn('document_id', $chunk)
                ->get();

            foreach ($rows as $row) {
                $docId = (int) $row->document_id;
                if (!isset($map[$docId]) && !empty($row->date_of_due)) {
                    $map[$docId] = $this->normalizeDate($row->date_of_due);
                }
            }
        }

        return $map;
    }

    /**
     * Batch-loads payment_evidences rows keyed by "{payable_type}:{payable_id}".
     *
     * @param  array<string, array<int, int>>  $idsByClass
     * @return array<string, array{sunat_payment_code: string, bancarization_exception: ?string}>
     */
    private function loadPaymentEvidencesMap(array $idsByClass): array
    {
        if (!$this->hasTable('payment_evidences')) {
            return [];
        }

        $map = [];
        foreach ($idsByClass as $class => $ids) {
            if (empty($ids)) {
                continue;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                $rows = DB::connection('tenant')
                    ->table('payment_evidences')
                    ->select(['payable_type', 'payable_id', 'sunat_payment_code', 'bancarization_exception'])
                    ->where('payable_type', $class)
                    ->whereIn('payable_id', $chunk)
                    ->get();

                foreach ($rows as $row) {
                    $key = $row->payable_type . ':' . ((int) $row->payable_id);
                    $map[$key] = [
                        'sunat_payment_code' => (string) ($row->sunat_payment_code ?? ''),
                        'bancarization_exception' => $row->bancarization_exception !== null && trim((string) $row->bancarization_exception) !== ''
                            ? trim((string) $row->bancarization_exception)
                            : null,
                    ];
                }
            }
        }

        return $map;
    }

    private function evaluateObligationBancarization(
        float $total,
        string $currency,
        string $dateOfIssue,
        ?float $exchangeRateSale,
        string $paymentClass,
        array $payments,
        array $evidences
    ): array {
        $cacheKey = \Modules\Treasury\Support\TenantCacheKey::prefix() . $currency . '|' . $dateOfIssue . '|' . ($exchangeRateSale !== null ? (string) $exchangeRateSale : 'null');
        if (!array_key_exists($cacheKey, $this->thresholdCache)) {
            $this->thresholdCache[$cacheKey] = $this->taxRateRepository->getBancarizationThreshold(
                $currency,
                $dateOfIssue,
                $exchangeRateSale
            );
        }
        $threshold = $this->thresholdCache[$cacheKey];
        $exceeds = ($threshold !== null && Money::round($total) >= $threshold);

        $paymentsCount = count($payments);
        $withoutEvidence = 0;

        if ($exceeds && $paymentsCount > 0) {
            foreach ($payments as $payment) {
                $key = $paymentClass . ':' . $payment['id'];
                if (!isset($evidences[$key])) {
                    $withoutEvidence++;
                    continue;
                }

                $ev = $evidences[$key];
                $isCashCode = in_array($ev['sunat_payment_code'], ['008', '009'], true);
                $hasValidException = $ev['bancarization_exception'] !== null
                    && in_array($ev['bancarization_exception'], BancarizationChecker::ALLOWED_EXCEPTIONS, true);

                if ($isCashCode && !$hasValidException) {
                    $withoutEvidence++;
                }
            }
        }

        $missingPaymentEvidence = $exceeds && $withoutEvidence > 0;
        $pendingRequiresBancarization = $exceeds && $paymentsCount === 0;
        $missingEvidence = $missingPaymentEvidence || $pendingRequiresBancarization;

        $alertReason = null;
        if ($missingPaymentEvidence) {
            $alertReason = 'payments_missing_evidence';
        } elseif ($pendingRequiresBancarization) {
            $alertReason = 'pending_requires_bancarization';
        }

        return [
            'threshold' => $threshold,
            'exceeds' => $exceeds,
            'payments_count' => $paymentsCount,
            'payments_without_evidence_count' => $withoutEvidence,
            'missing_payment_evidence' => $missingPaymentEvidence,
            'pending_requires_bancarization' => $pendingRequiresBancarization,
            'missing_evidence' => $missingEvidence,
            'alert_reason' => $alertReason,
        ];
    }

    private function resolveEffectiveExchangeRate(string $currency, ?float $docRate, ?float $usdFallbackRate): ?float
    {
        if ($currency === 'PEN') {
            return 1.0;
        }
        if ($docRate !== null && $docRate > 0) {
            return $docRate;
        }
        if ($currency === 'USD' && $usdFallbackRate !== null && $usdFallbackRate > 0) {
            return $usdFallbackRate;
        }

        return null;
    }

    private function extractDetractionPen($raw): float
    {
        if ($raw === null || $raw === '') {
            return 0.0;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : (is_object($raw) ? (array) $raw : $raw);
        if (!is_array($decoded) || !isset($decoded['amount']) || !is_numeric($decoded['amount'])) {
            return 0.0;
        }

        return Money::round(max(0.0, (float) $decoded['amount']));
    }

    private function convertPenToCurrency(float $amountPen, string $currency, ?float $exchangeRateSale): float
    {
        if ($amountPen <= 0.0) {
            return 0.0;
        }
        if ($currency === 'PEN') {
            return Money::round($amountPen);
        }
        if ($exchangeRateSale !== null && $exchangeRateSale > 0) {
            return Money::round($amountPen / $exchangeRateSale);
        }

        return 0.0;
    }

    private function extractRetentionAmount($raw): float
    {
        if ($raw === null || $raw === '') {
            return 0.0;
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : (is_object($raw) ? json_decode(json_encode($raw), true) : $raw);
        if (!is_array($decoded)) {
            return 0.0;
        }

        // Normalize single associative discount object into list form.
        $entries = array_key_exists('discount_type_id', $decoded) ? [$decoded] : array_values($decoded);
        $sum = 0.0;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((string) ($entry['discount_type_id'] ?? '') === '62' && isset($entry['amount']) && is_numeric($entry['amount'])) {
                $sum = Money::round($sum + max(0.0, (float) $entry['amount']));
            }
        }

        return $sum;
    }

    private function resolveAgingBucket(int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'current';
        }
        if ($daysOverdue <= 30) {
            return 'days_1_30';
        }
        if ($daysOverdue <= 60) {
            return 'days_31_60';
        }
        if ($daysOverdue <= 90) {
            return 'days_61_90';
        }

        return 'days_over_90';
    }

    private function resolveRowStatus(array $installments): string
    {
        $hasDueSoon = false;
        foreach ($installments as $inst) {
            if ($inst['status'] === 'overdue') {
                return 'overdue';
            }
            if ($inst['status'] === 'due_soon') {
                $hasDueSoon = true;
            }
        }

        return $hasDueSoon ? 'due_soon' : 'pending';
    }

    private function resolveEffectiveDueDate(array $installments, string $fallback): string
    {
        foreach ($installments as $inst) {
            if ($inst['pending_amount'] > 0.0) {
                return $inst['date'];
            }
        }

        if (!empty($installments)) {
            $last = end($installments);

            return $last['date'];
        }

        return $fallback;
    }

    private function resolveMaxDaysOverdue(array $installments): int
    {
        $max = 0;
        foreach ($installments as $inst) {
            if ($inst['pending_amount'] > 0.0 && $inst['days_overdue'] > $max) {
                $max = $inst['days_overdue'];
            }
        }

        return $max;
    }

    private function resolveMinDaysUntilDue(array $installments): ?int
    {
        $min = null;
        foreach ($installments as $inst) {
            if ($inst['pending_amount'] > 0.0 && $inst['days_overdue'] <= 0) {
                $days = (int) $inst['days_until_due'];
                if ($min === null || $days < $min) {
                    $min = $days;
                }
            }
        }

        return $min;
    }

    private function resolveLastPaymentDate(array $payments): ?string
    {
        $last = null;
        foreach ($payments as $p) {
            if (!empty($p['date_of_payment']) && ($last === null || $p['date_of_payment'] > $last)) {
                $last = $p['date_of_payment'];
            }
        }

        return $last;
    }

    private function resolveSaleNoteNumber(object $sn): string
    {
        if (!empty($sn->series) && !empty($sn->number)) {
            return trim((string) $sn->series) . '-' . trim((string) $sn->number);
        }
        if (!empty($sn->filename)) {
            return (string) $sn->filename;
        }
        if (!empty($sn->prefix)) {
            return trim((string) $sn->prefix) . '-' . $sn->id;
        }

        return 'NV-' . $sn->id;
    }

    private function sumPayments(array $payments): float
    {
        $sum = 0.0;
        foreach ($payments as $p) {
            $sum += (float) $p['payment'];
        }

        return Money::round($sum);
    }

    private function flattenPaymentIds(array $groupedPayments): array
    {
        $ids = [];
        foreach ($groupedPayments as $list) {
            foreach ($list as $p) {
                $ids[] = (int) $p['id'];
            }
        }

        return $ids;
    }

    private function matchesSearch(string $search, string $numberFull, ?array $person): bool
    {
        if (mb_strpos(mb_strtolower($numberFull), $search) !== false) {
            return true;
        }
        if ($person) {
            if (mb_strpos(mb_strtolower($person['name']), $search) !== false) {
                return true;
            }
            if (!empty($person['number']) && mb_strpos(mb_strtolower($person['number']), $search) !== false) {
                return true;
            }
        }

        return false;
    }

    private function sortScheduleRows(array &$rows): void
    {
        usort($rows, function (array $a, array $b) {
            if ($a['max_days_overdue'] !== $b['max_days_overdue']) {
                return $b['max_days_overdue'] <=> $a['max_days_overdue'];
            }
            $dueCmp = strcmp((string) $a['date_of_due'], (string) $b['date_of_due']);
            if ($dueCmp !== 0) {
                return $dueCmp;
            }

            return $a['id'] <=> $b['id'];
        });
    }

    private function buildScheduleTotals(array $rows): array
    {
        $byCurrency = [];
        $consolidatedPen = 0.0;
        $missingExchangeRate = false;

        foreach ($rows as $row) {
            $currency = $row['currency_type_id'];
            if (!isset($byCurrency[$currency])) {
                $byCurrency[$currency] = [
                    'currency_type_id' => $currency,
                    'count' => 0,
                    'total' => 0.0,
                    'total_paid' => 0.0,
                    'balance' => 0.0,
                    'overdue_balance' => 0.0,
                    'due_soon_balance' => 0.0,
                ];
            }

            $byCurrency[$currency]['count']++;
            $byCurrency[$currency]['total'] = Money::round($byCurrency[$currency]['total'] + $row['total']);
            $byCurrency[$currency]['total_paid'] = Money::round($byCurrency[$currency]['total_paid'] + $row['total_paid']);
            $byCurrency[$currency]['balance'] = Money::round($byCurrency[$currency]['balance'] + $row['balance']);

            foreach ($row['installments'] as $inst) {
                if ($inst['status'] === 'overdue') {
                    $byCurrency[$currency]['overdue_balance'] = Money::round(
                        $byCurrency[$currency]['overdue_balance'] + $inst['pending_amount']
                    );
                } elseif ($inst['status'] === 'due_soon') {
                    $byCurrency[$currency]['due_soon_balance'] = Money::round(
                        $byCurrency[$currency]['due_soon_balance'] + $inst['pending_amount']
                    );
                }
            }

            if ($row['balance_pen'] !== null) {
                $consolidatedPen = Money::round($consolidatedPen + $row['balance_pen']);
            } else {
                $missingExchangeRate = true;
            }
        }

        ksort($byCurrency);

        return [
            'count' => count($rows),
            'by_currency' => $byCurrency,
            'consolidated_pen' => $consolidatedPen,
            'missing_exchange_rate' => $missingExchangeRate,
        ];
    }

    private function sumAlertsByCurrency(array $items, string $field): array
    {
        $totals = [];
        foreach ($items as $item) {
            $c = $item['currency_type_id'];
            $totals[$c] = Money::round(($totals[$c] ?? 0.0) + (float) $item[$field]);
        }
        ksort($totals);

        return $totals;
    }

    private function emptyBucketTotals(): array
    {
        return [
            'current' => 0.0,
            'days_1_30' => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'days_over_90' => 0.0,
            'total' => 0.0,
        ];
    }

    private function emptySchedulePayload(string $asOf): array
    {
        return [
            'as_of_date' => $asOf,
            'rows' => [],
            'totals' => [
                'count' => 0,
                'by_currency' => [],
                'consolidated_pen' => 0.0,
                'missing_exchange_rate' => false,
            ],
        ];
    }

    private function resolveLatestExchangeRateSale(string $asOf): ?float
    {
        if (!$this->hasTable('exchange_rates')) {
            return null;
        }

        try {
            $row = DB::connection('tenant')
                ->table('exchange_rates')
                ->where('date', '<=', $asOf)
                ->orderBy('date', 'desc')
                ->first(['sale']);

            if (!$row) {
                $row = DB::connection('tenant')
                    ->table('exchange_rates')
                    ->orderBy('date', 'desc')
                    ->first(['sale']);
            }

            return ($row && (float) $row->sale > 0) ? (float) $row->sale : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveAsOfDate(?string $asOfDate): string
    {
        if ($asOfDate !== null && trim($asOfDate) !== '') {
            return Carbon::parse($asOfDate)->format('Y-m-d');
        }

        return Carbon::now()->format('Y-m-d');
    }

    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $str = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $str, $m)) {
            return $m[0];
        }

        try {
            return Carbon::parse($str)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resetCaches(): void
    {
        $this->thresholdCache = [];
        $this->schemaCache = [];
    }

    private function hasTable(string $table): bool
    {
        $key = \Modules\Treasury\Support\TenantCacheKey::prefix() . 't:' . $table;
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }

        if (!Model::getConnectionResolver()) {
            return $this->schemaCache[$key] = false;
        }

        try {
            return $this->schemaCache[$key] = DB::connection('tenant')->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return $this->schemaCache[$key] = false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = \Modules\Treasury\Support\TenantCacheKey::prefix() . 'c:' . $table . '.' . $column;
        if (array_key_exists($key, $this->schemaCache)) {
            return $this->schemaCache[$key];
        }

        if (!$this->hasTable($table)) {
            return $this->schemaCache[$key] = false;
        }

        try {
            return $this->schemaCache[$key] = DB::connection('tenant')->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return $this->schemaCache[$key] = false;
        }
    }
}
