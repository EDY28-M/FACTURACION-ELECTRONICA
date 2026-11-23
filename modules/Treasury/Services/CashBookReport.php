<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Pos\Models\CashTransaction;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\Money;

/**
 * Builds SUNAT Libro Caja y Bancos reports with official minimum information:
 * - Formato 1.1: Detalle de los movimientos del efectivo (Cash / PEN)
 * - Formato 1.2: Detalle de los movimientos de la cuenta corriente (BankAccount / account currency)
 *
 * PLE TXT (010100/010200) export is intentionally NOT implemented here (deferred pending RS 286-2009 Anexo 2).
 * Never calls auth() internally; callers must pass explicit $scope.
 */
class CashBookReport
{
    public const PCGE_NAMES = [
        '101' => 'Caja',
        '1041' => 'Cuentas corrientes operativas',
        '1071' => 'Fondos sujetos a restricción - Detracciones BN',
        '1212' => 'Facturas, boletas y otros comprobantes por cobrar - Emitidas en cartera',
        '401' => 'Gobierno central - Tributos por pagar',
        '4212' => 'Facturas, boletas y otros comprobantes por pagar - Emitidas',
        '63' => 'Gastos de servicios prestados por terceros',
        '639' => 'Otros servicios prestados por terceros (comisiones bancarias)',
        '641' => 'Gobierno central - ITF',
        '659' => 'Otros gastos de gestión',
        '759' => 'Otros ingresos de gestión',
        '772' => 'Rendimientos ganados',
    ];

    private $balanceService;

    /** @var array<string, bool> */
    private $tableExistsCache = [];

    /** @var array<string, bool> */
    private $columnExistsCache = [];

    public function __construct(TreasuryBalanceService $balanceService)
    {
        $this->balanceService = $balanceService;
    }

    /**
     * Formato 1.1: Libro Caja y Bancos - Detalle de los movimientos del efectivo (PEN).
     */
    public function buildFormat11(array $filters, array $scope): array
    {
        $this->resetSchemaCaches();
        [$dateStart, $dateEnd] = $this->resolveAndValidateDateRange($filters);

        $isAdmin = !empty($scope['is_admin']);
        $scopeEstId = isset($scope['establishment_id']) && (int) $scope['establishment_id'] > 0
            ? (int) $scope['establishment_id']
            : null;
        $userId = isset($scope['user_id']) && (int) $scope['user_id'] > 0 ? (int) $scope['user_id'] : null;

        if (!$isAdmin && ($scopeEstId === null || $userId === null)) {
            throw new AuthorizationException('Su usuario no tiene una sede asignada para consultar el Libro Caja y Bancos.');
        }

        $effectiveEstId = $isAdmin
            ? (!empty($filters['establishment_id']) ? (int) $filters['establishment_id'] : null)
            : $scopeEstId;

        $cashUserId = $isAdmin ? null : $userId;
        $cashId = !empty($filters['cash_id']) ? (int) $filters['cash_id'] : null;
        $cashLabel = 'Todas las cajas (Efectivo PEN)';

        if ($cashId !== null) {
            $cash = Cash::with('user')->find($cashId);
            if (!$cash) {
                throw ValidationException::withMessages(['cash_id' => ['La caja seleccionada no existe.']]);
            }
            if (!$isAdmin) {
                if ((int) $cash->user_id !== $userId) {
                    throw new AuthorizationException('No tiene permiso para consultar una caja de otro usuario.');
                }
                $cashEstId = ($cash->user && $cash->user->establishment_id) ? (int) $cash->user->establishment_id : null;
                if ($cashEstId !== null && $cashEstId !== $scopeEstId) {
                    throw new AuthorizationException('No tiene permiso para consultar una caja de otra sede.');
                }
            }
            $ownerName = $cash->user ? $cash->user->name : ('Usuario #' . $cash->user_id);
            $ref = $cash->reference_number ? " ({$cash->reference_number})" : '';
            $cashLabel = "Caja POS{$ref} - {$ownerName}";
        }

        $scopedCashIds = $this->resolveScopedCashIds($effectiveEstId, $cashId, $cashUserId);
        $dayBeforeStart = Carbon::parse($dateStart)->subDay()->format('Y-m-d');

        $openingBalance = $this->balanceService->getCashBalanceAsOf($dayBeforeStart, $effectiveEstId, $cashId, true, $cashUserId);
        $systemClosingBalance = $this->balanceService->getCashBalanceAsOf($dateEnd, $effectiveEstId, $cashId, true, $cashUserId);

        $rawEntries = [];
        if ($scopedCashIds === null || !empty($scopedCashIds)) {
            $rawEntries = array_merge(
                $this->loadGlobalPaymentRowsForCash($dateStart, $dateEnd, $scopedCashIds),
                $this->loadTreasuryMovementRows(Cash::class, $scopedCashIds, $dateStart, $dateEnd, $cashId !== null ? null : $effectiveEstId, 'PEN')
            );
        }

        usort($rawEntries, [$this, 'compareEntries']);

        $rows = [];
        $running = $openingBalance;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $seq = 1;

        foreach ($rawEntries as $entry) {
            $debit = $entry['direction'] >= 0 ? Money::round($entry['amount']) : 0.0;
            $credit = $entry['direction'] < 0 ? Money::round($entry['amount']) : 0.0;
            $running = Money::round($running + $debit - $credit);
            $totalDebit = Money::round($totalDebit + $debit);
            $totalCredit = Money::round($totalCredit + $credit);

            $rows[] = [
                'correlative' => str_pad((string) $seq++, 6, '0', STR_PAD_LEFT),
                'date' => $entry['date'],
                'description' => $entry['description'],
                'pcge_code' => $entry['pcge_code'],
                'pcge_name' => $entry['pcge_name'],
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
                'origin' => $entry['origin'],
            ];
        }

        // Credit-note adjustments that take effect during [date_start, date_end]
        $cnEnd = $this->balanceService->getCashCreditNoteAdjustments(null, $dateEnd, $effectiveEstId, $cashId, $cashUserId);
        $cnBefore = $this->balanceService->getCashCreditNoteAdjustments(null, $dayBeforeStart, $effectiveEstId, $cashId, $cashUserId);
        $periodCnAdjustments = $this->diffCreditNoteAdjustments($cnEnd, $cnBefore);
        $cnAdjustmentsTotal = Money::round((float) array_sum(array_column($periodCnAdjustments, 'amount')));

        $periodNet = Money::round($totalDebit - $totalCredit);
        $closingBalance = Money::round($openingBalance + $periodNet);

        $company = $this->loadCompanyMeta();
        $establishmentLabel = $this->resolveEstablishmentLabel($effectiveEstId);

        return [
            'meta' => [
                'format' => '1.1',
                'title' => 'FORMATO 1.1: LIBRO CAJA Y BANCOS - DETALLE DE LOS MOVIMIENTOS DEL EFECTIVO',
                'company_ruc' => $company['ruc'],
                'company_name' => $company['name'],
                'period_label' => $this->formatPeriodLabel($dateStart, $dateEnd),
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'establishment_id' => $effectiveEstId,
                'establishment_label' => $establishmentLabel,
                'cash_id' => $cashId,
                'cash_label' => $cashLabel,
                'currency_type_id' => 'PEN',
            ],
            'opening_balance' => $openingBalance,
            'rows' => $rows,
            'credit_note_adjustments' => $periodCnAdjustments,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'period_net' => $periodNet,
                'closing_balance' => $closingBalance,
                'credit_note_adjustments' => $cnAdjustmentsTotal,
                'system_closing_balance' => $systemClosingBalance,
                'reconciliation_difference' => Money::round($closingBalance + $cnAdjustmentsTotal - $systemClosingBalance),
            ],
        ];
    }

    /**
     * Formato 1.2: Libro Caja y Bancos - Detalle de los movimientos de la cuenta corriente (native account currency).
     */
    public function buildFormat12(array $filters, array $scope): array
    {
        $this->resetSchemaCaches();
        [$dateStart, $dateEnd] = $this->resolveAndValidateDateRange($filters);

        $isAdmin = !empty($scope['is_admin']);
        $scopeEstId = isset($scope['establishment_id']) && (int) $scope['establishment_id'] > 0
            ? (int) $scope['establishment_id']
            : null;

        if (!$isAdmin && $scopeEstId === null) {
            throw new AuthorizationException('Su usuario no tiene una sede asignada para consultar el Libro Caja y Bancos.');
        }

        $bankAccountId = !empty($filters['bank_account_id']) ? (int) $filters['bank_account_id'] : 0;
        if ($bankAccountId <= 0) {
            throw ValidationException::withMessages(['bank_account_id' => ['Seleccione una cuenta bancaria para generar el Formato 1.2.']]);
        }

        $account = BankAccount::with(['bank', 'establishment'])->find($bankAccountId);
        if (!$account) {
            throw ValidationException::withMessages(['bank_account_id' => ['La cuenta bancaria seleccionada no existe.']]);
        }

        if (!$isAdmin && $account->establishment_id !== null && (int) $account->establishment_id !== $scopeEstId) {
            throw new AuthorizationException('No tiene permiso para consultar una cuenta bancaria de otra sede.');
        }

        $targetCurrency = strtoupper((string) ($account->currency_type_id ?: 'PEN'));
        $dayBeforeStart = Carbon::parse($dateStart)->subDay()->format('Y-m-d');

        $openingBalance = (float) $this->balanceService->getBankAccountBalanceAsOf($bankAccountId, $dayBeforeStart)['balance'];
        $systemClosingBalance = (float) $this->balanceService->getBankAccountBalanceAsOf($bankAccountId, $dateEnd)['balance'];

        $rawEntries = array_merge(
            $this->loadGlobalPaymentRowsForBankAccount($bankAccountId, $targetCurrency, $dateStart, $dateEnd),
            $this->loadTreasuryMovementRows(BankAccount::class, [$bankAccountId], $dateStart, $dateEnd, null, $targetCurrency)
        );

        usort($rawEntries, [$this, 'compareEntries']);

        // Batch-load PaymentEvidence and PaymentMethodType sunat_codes (zero N+1)
        $evidenceMap = $this->loadEvidenceBatch($rawEntries);
        $paymentMethodSunatMap = $this->loadPaymentMethodSunatMap();

        $rows = [];
        $running = $openingBalance;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $seq = 1;

        foreach ($rawEntries as $entry) {
            $evKey = $entry['payable_type'] . ':' . $entry['payable_id'];
            $evidence = $evidenceMap[$evKey] ?? null;

            $sunatCode = $this->resolveSunatPaymentCode(
                $evidence['sunat_payment_code'] ?? null,
                $entry['sunat_payment_code'] ?? null,
                $entry['payment_method_type_id'] ?? null,
                $paymentMethodSunatMap
            );
            $sunatLabel = BancarizationChecker::SUNAT_PAYMENT_CODES[$sunatCode] ?? 'Otros medios de pago';

            $counterparty = $this->firstNonEmpty([
                $evidence['payer_or_beneficiary_name'] ?? null,
                $entry['counterparty'] ?? null,
            ]) ?: '-';

            $transactionNumber = $this->firstNonEmpty([
                $evidence['operation_number'] ?? null,
                $entry['operation_number'] ?? null,
                $entry['reference'] ?? null,
                $entry['document_number'] ?? null,
            ]) ?: '-';

            $debit = $entry['direction'] >= 0 ? Money::round($entry['amount']) : 0.0;
            $credit = $entry['direction'] < 0 ? Money::round($entry['amount']) : 0.0;
            $running = Money::round($running + $debit - $credit);
            $totalDebit = Money::round($totalDebit + $debit);
            $totalCredit = Money::round($totalCredit + $credit);

            $rows[] = [
                'correlative' => str_pad((string) $seq++, 6, '0', STR_PAD_LEFT),
                'date' => $entry['date'],
                'sunat_payment_code' => $sunatCode,
                'sunat_payment_label' => $sunatLabel,
                'description' => $entry['description'],
                'counterparty' => $counterparty,
                'transaction_number' => $transactionNumber,
                'pcge_code' => $entry['pcge_code'],
                'pcge_name' => $entry['pcge_name'],
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
                'origin' => $entry['origin'],
            ];
        }

        // Period credit-note adjustments reconciled against system_closing_balance
        $cnEnd = $this->balanceService->getBankAccountCreditNoteAdjustments($bankAccountId, $dateEnd);
        $cnBefore = $this->balanceService->getBankAccountCreditNoteAdjustments($bankAccountId, $dayBeforeStart);
        $periodCnAdjustments = $this->diffCreditNoteAdjustments($cnEnd, $cnBefore);
        $cnAdjustmentsTotal = Money::round((float) array_sum(array_column($periodCnAdjustments, 'amount')));

        $periodNet = Money::round($totalDebit - $totalCredit);
        $closingBalance = Money::round($openingBalance + $periodNet);

        $company = $this->loadCompanyMeta();
        $bankName = $account->bank ? (string) $account->bank->description : 'Entidad financiera';

        return [
            'meta' => [
                'format' => '1.2',
                'title' => 'FORMATO 1.2: LIBRO CAJA Y BANCOS - DETALLE DE LOS MOVIMIENTOS DE LA CUENTA CORRIENTE',
                'company_ruc' => $company['ruc'],
                'company_name' => $company['name'],
                'bank_account_id' => (int) $account->id,
                'bank_name' => $bankName,
                'account_number' => (string) ($account->number ?: '-'),
                'account_description' => (string) ($account->description ?: '-'),
                'currency_type_id' => $targetCurrency,
                'pcge_account' => (string) ($account->pcge_account ?: '1041'),
                'period_label' => $this->formatPeriodLabel($dateStart, $dateEnd),
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
            ],
            'opening_balance' => $openingBalance,
            'rows' => $rows,
            'credit_note_adjustments' => $periodCnAdjustments,
            'totals' => [
                'debit' => $totalDebit,
                'credit' => $totalCredit,
                'period_net' => $periodNet,
                'closing_balance' => $closingBalance,
                'credit_note_adjustments' => $cnAdjustmentsTotal,
                'system_closing_balance' => $systemClosingBalance,
                'reconciliation_difference' => Money::round($closingBalance + $cnAdjustmentsTotal - $systemClosingBalance),
            ],
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Movement Loaders (Batch / Zero N+1)
    // ---------------------------------------------------------------------------------------------------------

    private function loadGlobalPaymentRowsForCash(string $dateStart, string $dateEnd, ?array $scopedCashIds): array
    {
        return $this->loadGlobalPaymentRows(Cash::class, $scopedCashIds, 'PEN', $dateStart, $dateEnd, true);
    }

    private function loadGlobalPaymentRowsForBankAccount(int $bankAccountId, string $targetCurrency, string $dateStart, string $dateEnd): array
    {
        return $this->loadGlobalPaymentRows(BankAccount::class, [$bankAccountId], $targetCurrency, $dateStart, $dateEnd, false);
    }

    private function loadGlobalPaymentRows(
        string $destinationType,
        ?array $destinationIds,
        string $targetCurrency,
        string $dateStart,
        string $dateEnd,
        bool $includeCashTransactions
    ): array {
        if (!$this->hasTable('global_payments')) {
            return [];
        }

        $entries = [];
        $personIds = [];
        $incomeTable = $this->resolveIncomeTable();

        $specs = [
            [
                'class' => DocumentPayment::class, 'pay' => 'document_payments', 'fk' => 'document_id',
                'parent' => 'documents', 'direction' => 1, 'has_state' => true, 'has_changed' => false,
                'has_currency' => true, 'person_col' => 'customer_id', 'number_mode' => 'series_number',
                'label' => 'Cobro comprobante', 'pcge' => '1212',
            ],
            [
                'class' => SaleNotePayment::class, 'pay' => 'sale_note_payments', 'fk' => 'sale_note_id',
                'parent' => 'sale_notes', 'direction' => 1, 'has_state' => true, 'has_changed' => true,
                'has_currency' => true, 'person_col' => 'customer_id', 'number_mode' => 'sale_note',
                'label' => 'Cobro nota de venta', 'pcge' => '1212',
            ],
            [
                'class' => QuotationPayment::class, 'pay' => 'quotation_payments', 'fk' => 'quotation_id',
                'parent' => 'quotations', 'direction' => 1, 'has_state' => true, 'has_changed' => true,
                'has_currency' => true, 'person_col' => 'customer_id', 'number_mode' => 'prefix_id',
                'label' => 'Cobro cotización', 'pcge' => '1212',
            ],
            [
                'class' => ContractPayment::class, 'pay' => 'contract_payments', 'fk' => 'contract_id',
                'parent' => 'contracts', 'direction' => 1, 'has_state' => true, 'has_changed' => true,
                'has_currency' => true, 'person_col' => 'customer_id', 'number_mode' => 'prefix_id',
                'label' => 'Cobro contrato', 'pcge' => '1212',
            ],
            [
                'class' => IncomePayment::class, 'pay' => 'income_payments', 'fk' => 'income_id',
                'parent' => $incomeTable, 'direction' => 1, 'has_state' => true, 'has_changed' => false,
                'has_currency' => true, 'person_col' => null, 'customer_text_col' => 'customer', 'number_mode' => 'number',
                'label' => 'Ingreso', 'pcge' => '759',
            ],
            [
                'class' => TechnicalServicePayment::class, 'pay' => 'technical_service_payments', 'fk' => 'technical_service_id',
                'parent' => 'technical_services', 'direction' => 1, 'has_state' => false, 'has_changed' => false,
                'has_currency' => false, 'person_col' => 'customer_id', 'number_mode' => 'id_only',
                'label' => 'Cobro servicio técnico', 'pcge' => '1212',
            ],
            [
                'class' => PurchasePayment::class, 'pay' => 'purchase_payments', 'fk' => 'purchase_id',
                'parent' => 'purchases', 'direction' => -1, 'has_state' => true, 'has_changed' => false,
                'has_currency' => true, 'person_col' => 'supplier_id', 'number_mode' => 'series_number',
                'label' => 'Pago compra', 'pcge' => '4212',
            ],
            [
                'class' => ExpensePayment::class, 'pay' => 'expense_payments', 'fk' => 'expense_id',
                'parent' => 'expenses', 'direction' => -1, 'has_state' => true, 'has_changed' => false,
                'has_currency' => true, 'person_col' => 'supplier_id', 'number_mode' => 'number',
                'label' => 'Pago gasto', 'pcge' => '63',
            ],
        ];

        foreach ($specs as $spec) {
            if (empty($spec['parent']) || !$this->hasTable($spec['pay']) || !$this->hasTable($spec['parent'])) {
                continue;
            }

            $q = DB::connection('tenant')
                ->table('global_payments as gp')
                ->join($spec['pay'] . ' as p', 'p.id', '=', 'gp.payment_id')
                ->join($spec['parent'] . ' as par', 'par.id', '=', 'p.' . $spec['fk'])
                ->where('gp.destination_type', $destinationType)
                ->where('gp.payment_type', $spec['class'])
                ->where('p.date_of_payment', '>=', $dateStart)
                ->where('p.date_of_payment', '<=', $dateEnd);

            if ($destinationIds !== null) {
                $q->whereIn('gp.destination_id', $destinationIds);
            }
            if ($spec['has_state']) {
                $q->whereIn('par.state_type_id', TreasuryBalanceService::ACCEPTED_STATE_TYPES);
            }
            if ($spec['has_changed']) {
                $q->where('par.changed', false);
            }

            $select = [
                'gp.id as gp_id',
                'p.id as pay_id',
                'p.date_of_payment',
                'p.payment',
                'par.id as parent_id',
            ];

            $hasReference = $this->hasColumn($spec['pay'], 'reference');
            if ($hasReference) {
                $select[] = 'p.reference';
            }

            $hasPaymentMethodCol = $this->hasColumn($spec['pay'], 'payment_method_type_id');
            if ($hasPaymentMethodCol) {
                $select[] = 'p.payment_method_type_id';
            }

            if ($spec['has_currency']) {
                $select[] = 'par.currency_type_id';
                $select[] = 'par.exchange_rate_sale';
            }

            if ($spec['person_col'] && $this->hasColumn($spec['parent'], $spec['person_col'])) {
                $select[] = 'par.' . $spec['person_col'] . ' as person_id';
            }
            if (!empty($spec['customer_text_col']) && $this->hasColumn($spec['parent'], $spec['customer_text_col'])) {
                $select[] = 'par.' . $spec['customer_text_col'] . ' as counterparty_text';
            }

            if ($spec['number_mode'] === 'series_number') {
                $select[] = 'par.series';
                $select[] = 'par.number';
            } elseif ($spec['number_mode'] === 'sale_note') {
                if ($this->hasColumn($spec['parent'], 'series')) {
                    $select[] = 'par.series';
                }
                if ($this->hasColumn($spec['parent'], 'number')) {
                    $select[] = 'par.number';
                }
                if ($this->hasColumn($spec['parent'], 'prefix')) {
                    $select[] = 'par.prefix';
                }
            } elseif ($spec['number_mode'] === 'prefix_id') {
                if ($this->hasColumn($spec['parent'], 'prefix')) {
                    $select[] = 'par.prefix';
                }
            } elseif ($spec['number_mode'] === 'number') {
                if ($this->hasColumn($spec['parent'], 'number')) {
                    $select[] = 'par.number';
                }
            }

            foreach ($q->get($select) as $row) {
                $sourceCur = $spec['has_currency'] ? strtoupper((string) ($row->currency_type_id ?: 'PEN')) : 'PEN';
                $rate = ($spec['has_currency'] && isset($row->exchange_rate_sale) && (float) $row->exchange_rate_sale > 0)
                    ? (float) $row->exchange_rate_sale
                    : 1.0;

                $converted = $this->convertAmount((float) $row->payment, $sourceCur, $rate, $targetCurrency);
                $docNumber = $this->formatParentNumber($row, $spec['number_mode']);
                $description = $docNumber !== '' ? ($spec['label'] . ' ' . $docNumber) : $spec['label'];

                $personId = isset($row->person_id) && (int) $row->person_id > 0 ? (int) $row->person_id : null;
                if ($personId !== null) {
                    $personIds[$personId] = true;
                }

                $entries[] = [
                    'date' => substr((string) $row->date_of_payment, 0, 10),
                    'sort_origin' => 1,
                    'sort_id' => (int) $row->gp_id,
                    'direction' => $spec['direction'],
                    'amount' => Money::round($converted),
                    'description' => $description,
                    'pcge_code' => $spec['pcge'],
                    'pcge_name' => self::PCGE_NAMES[$spec['pcge']] ?? $spec['pcge'],
                    'payable_type' => $spec['class'],
                    'payable_id' => (int) $row->pay_id,
                    'payment_method_type_id' => $hasPaymentMethodCol ? ($row->payment_method_type_id ?: null) : null,
                    'sunat_payment_code' => null,
                    'operation_number' => null,
                    'reference' => $hasReference ? ($row->reference ?: null) : null,
                    'document_number' => $docNumber !== '' ? $docNumber : null,
                    'person_id' => $personId,
                    'counterparty' => isset($row->counterparty_text) ? (string) $row->counterparty_text : null,
                    'origin' => 'global_payment',
                ];
            }
        }

        // CashTransaction (Cash POS only)
        if ($includeCashTransactions && $this->hasTable('cash_transactions')) {
            $txQuery = DB::connection('tenant')
                ->table('global_payments as gp')
                ->join('cash_transactions as ct', 'ct.id', '=', 'gp.payment_id')
                ->where('gp.destination_type', Cash::class)
                ->where('gp.payment_type', CashTransaction::class)
                ->where('ct.date', '>=', $dateStart)
                ->where('ct.date', '<=', $dateEnd);

            if ($destinationIds !== null) {
                $txQuery->whereIn('gp.destination_id', $destinationIds);
            }

            $txSelect = ['gp.id as gp_id', 'ct.id as ct_id', 'ct.date', 'ct.payment'];
            if ($this->hasColumn('cash_transactions', 'description')) {
                $txSelect[] = 'ct.description';
            }
            if ($this->hasColumn('cash_transactions', 'payment_method_type_id')) {
                $txSelect[] = 'ct.payment_method_type_id';
            }

            foreach ($txQuery->get($txSelect) as $row) {
                $entries[] = [
                    'date' => substr((string) $row->date, 0, 10),
                    'sort_origin' => 1,
                    'sort_id' => (int) $row->gp_id,
                    'direction' => 1,
                    'amount' => Money::round((float) $row->payment),
                    'description' => !empty($row->description) ? (string) $row->description : 'Movimiento de caja POS',
                    'pcge_code' => '101',
                    'pcge_name' => self::PCGE_NAMES['101'],
                    'payable_type' => CashTransaction::class,
                    'payable_id' => (int) $row->ct_id,
                    'payment_method_type_id' => $row->payment_method_type_id ?? null,
                    'sunat_payment_code' => null,
                    'operation_number' => null,
                    'reference' => null,
                    'document_number' => 'CAJA-' . $row->ct_id,
                    'person_id' => null,
                    'counterparty' => null,
                    'origin' => 'global_payment',
                ];
            }
        }

        // Hydrate persons in a single batch query
        $personsMap = $this->loadPersonsMap(array_keys($personIds));
        foreach ($entries as &$entry) {
            if (empty($entry['counterparty']) && !empty($entry['person_id']) && isset($personsMap[$entry['person_id']])) {
                $entry['counterparty'] = $personsMap[$entry['person_id']];
            }
        }
        unset($entry);

        return $entries;
    }

    private function loadTreasuryMovementRows(
        string $destinationType,
        ?array $destinationIds,
        string $dateStart,
        string $dateEnd,
        ?int $establishmentId,
        string $targetCurrency
    ): array {
        if (!$this->hasTable('treasury_movements')) {
            return [];
        }

        $q = DB::connection('tenant')
            ->table('treasury_movements')
            ->where('state', TreasuryMovement::STATE_ACTIVE)
            ->where('destination_type', $destinationType)
            ->whereDate('date', '>=', $dateStart)
            ->whereDate('date', '<=', $dateEnd);

        if ($destinationIds !== null) {
            $q->whereIn('destination_id', $destinationIds);
        }
        if ($establishmentId !== null) {
            $q->where('establishment_id', $establishmentId);
        }

        $rows = $q->get([
            'id',
            'date',
            'kind',
            'direction',
            'currency_type_id',
            'amount',
            'amount_pen',
            'payment_method_type_id',
            'sunat_payment_code',
            'operation_number',
            'counterparty_person_id',
            'counterparty_name',
            'treasury_concept_id',
            'description',
        ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $conceptsMap = $this->loadConceptsMap();
        $personIds = [];
        foreach ($rows as $row) {
            if (!empty($row->counterparty_person_id)) {
                $personIds[(int) $row->counterparty_person_id] = true;
            }
        }
        $personsMap = $this->loadPersonsMap(array_keys($personIds));

        $entries = [];
        foreach ($rows as $row) {
            $amount = ($targetCurrency === 'PEN')
                ? (float) $row->amount_pen
                : (float) $row->amount;

            $conceptId = $row->treasury_concept_id !== null ? (int) $row->treasury_concept_id : null;
            $concept = $conceptId !== null ? ($conceptsMap[$conceptId] ?? null) : null;
            [$pcgeCode, $pcgeName] = $this->resolveMovementPcge((string) $row->kind, (int) $row->direction, $concept);

            $counterparty = $this->firstNonEmpty([
                $row->counterparty_name ?? null,
                !empty($row->counterparty_person_id) ? ($personsMap[(int) $row->counterparty_person_id] ?? null) : null,
            ]);

            $description = trim((string) ($row->description ?? ''));
            if ($description === '') {
                $description = $concept['description'] ?? (MovementService::KIND_LABELS[(string) $row->kind] ?? (string) $row->kind);
            }

            $entries[] = [
                'date' => substr((string) $row->date, 0, 10),
                'sort_origin' => 2,
                'sort_id' => (int) $row->id,
                'direction' => (int) $row->direction,
                'amount' => Money::round($amount),
                'description' => $description,
                'pcge_code' => $pcgeCode,
                'pcge_name' => $pcgeName,
                'payable_type' => TreasuryMovement::class,
                'payable_id' => (int) $row->id,
                'payment_method_type_id' => $row->payment_method_type_id ?: null,
                'sunat_payment_code' => $row->sunat_payment_code ?: null,
                'operation_number' => $row->operation_number ?: null,
                'reference' => null,
                'document_number' => null,
                'person_id' => $row->counterparty_person_id ? (int) $row->counterparty_person_id : null,
                'counterparty' => $counterparty,
                'origin' => 'treasury_movement',
            ];
        }

        return $entries;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    private function resolveAndValidateDateRange(array $filters): array
    {
        $dateStart = !empty($filters['date_start'])
            ? (string) $filters['date_start']
            : Carbon::today()->startOfMonth()->format('Y-m-d');
        $dateEnd = !empty($filters['date_end'])
            ? (string) $filters['date_end']
            : Carbon::today()->format('Y-m-d');

        if ($dateStart > $dateEnd) {
            throw ValidationException::withMessages([
                'date_end' => ['La fecha final debe ser igual o posterior a la fecha inicial.'],
            ]);
        }

        return [$dateStart, $dateEnd];
    }

    private function resolveScopedCashIds(?int $establishmentId, ?int $cashId, ?int $userId = null): ?array
    {
        if ($cashId !== null) {
            return [$cashId];
        }
        if (($establishmentId !== null || $userId !== null) && $this->hasTable('cash')) {
            $q = DB::connection('tenant')->table('cash');
            if ($establishmentId !== null && $this->hasTable('users')) {
                $q->join('users', 'users.id', '=', 'cash.user_id')
                    ->where('users.establishment_id', $establishmentId);
            }
            if ($userId !== null) {
                $q->where('cash.user_id', $userId);
            }

            return $q->pluck('cash.id')->map('intval')->all();
        }

        return null;
    }

    private function compareEntries(array $a, array $b): int
    {
        $cmp = strcmp($a['date'], $b['date']);
        if ($cmp !== 0) {
            return $cmp;
        }
        if ($a['sort_origin'] !== $b['sort_origin']) {
            return $a['sort_origin'] <=> $b['sort_origin'];
        }

        return $a['sort_id'] <=> $b['sort_id'];
    }

    private function diffCreditNoteAdjustments(array $endList, array $beforeList): array
    {
        $beforeKeys = [];
        foreach ($beforeList as $item) {
            $beforeKeys[$item['key']] = true;
        }

        $diff = [];
        foreach ($endList as $item) {
            if (!isset($beforeKeys[$item['key']])) {
                $diff[] = $item;
            }
        }

        return $diff;
    }

    private function resolveMovementPcge(string $kind, int $direction, ?array $concept): array
    {
        if ($concept && !empty($concept['pcge_account'])) {
            $code = trim((string) $concept['pcge_account']);
            $name = self::PCGE_NAMES[$code] ?? ($concept['description'] ?: $code);

            return [$code, $name];
        }

        $map = [
            'transfer_in' => '1041',
            'transfer_out' => '1041',
            'tax_payment' => '401',
            'itf' => '641',
            'bank_fee' => '639',
            'interest' => '772',
            'detraction_deposit' => '1071',
            'detraction_release' => '1071',
            'income' => '759',
            'expense' => '659',
            'adjustment' => $direction >= 0 ? '759' : '659',
        ];
        $code = $map[$kind] ?? ($direction >= 0 ? '759' : '659');

        return [$code, self::PCGE_NAMES[$code] ?? $code];
    }

    private function resolveSunatPaymentCode(
        ?string $evidenceCode,
        ?string $movementCode,
        ?string $paymentMethodTypeId,
        array $paymentMethodSunatMap
    ): string {
        foreach ([$evidenceCode, $movementCode] as $candidate) {
            $trimmed = $candidate !== null ? trim($candidate) : '';
            if ($trimmed !== '' && isset(BancarizationChecker::SUNAT_PAYMENT_CODES[$trimmed])) {
                return $trimmed;
            }
        }

        if ($paymentMethodTypeId !== null && isset($paymentMethodSunatMap[$paymentMethodTypeId])) {
            $code = $paymentMethodSunatMap[$paymentMethodTypeId];
            if (isset(BancarizationChecker::SUNAT_PAYMENT_CODES[$code])) {
                return $code;
            }
        }

        return '003';
    }

    private function loadEvidenceBatch(array $entries): array
    {
        if (empty($entries) || !$this->hasTable('payment_evidences')) {
            return [];
        }

        $byType = [];
        foreach ($entries as $entry) {
            if (!empty($entry['payable_type']) && !empty($entry['payable_id'])) {
                $byType[$entry['payable_type']][(int) $entry['payable_id']] = true;
            }
        }
        if (empty($byType)) {
            return [];
        }

        $map = [];
        foreach ($byType as $type => $ids) {
            foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                $rows = DB::connection('tenant')
                    ->table('payment_evidences')
                    ->where('payable_type', $type)
                    ->whereIn('payable_id', $chunk)
                    ->get(['payable_type', 'payable_id', 'sunat_payment_code', 'operation_number', 'payer_or_beneficiary_name']);

                foreach ($rows as $row) {
                    $key = $row->payable_type . ':' . (int) $row->payable_id;
                    $map[$key] = [
                        'sunat_payment_code' => $row->sunat_payment_code ? (string) $row->sunat_payment_code : null,
                        'operation_number' => $row->operation_number ? (string) $row->operation_number : null,
                        'payer_or_beneficiary_name' => $row->payer_or_beneficiary_name ? (string) $row->payer_or_beneficiary_name : null,
                    ];
                }
            }
        }

        return $map;
    }

    private function loadPaymentMethodSunatMap(): array
    {
        if (!$this->hasTable('payment_method_types') || !$this->hasColumn('payment_method_types', 'sunat_code')) {
            return [];
        }

        $map = [];
        foreach (DB::connection('tenant')->table('payment_method_types')->get(['id', 'sunat_code']) as $row) {
            if (!empty($row->sunat_code)) {
                $map[(string) $row->id] = trim((string) $row->sunat_code);
            }
        }

        return $map;
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

    private function loadPersonsMap(array $ids): array
    {
        if (empty($ids) || !$this->hasTable('persons')) {
            return [];
        }

        $map = [];
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            foreach (DB::connection('tenant')->table('persons')->whereIn('id', $chunk)->get(['id', 'name']) as $row) {
                $map[(int) $row->id] = (string) $row->name;
            }
        }

        return $map;
    }

    private function loadCompanyMeta(): array
    {
        if (!$this->hasTable('companies')) {
            return ['ruc' => '-', 'name' => '-'];
        }

        $cols = [];
        if ($this->hasColumn('companies', 'number')) {
            $cols[] = 'number';
        }
        if ($this->hasColumn('companies', 'name')) {
            $cols[] = 'name';
        }
        if (empty($cols)) {
            return ['ruc' => '-', 'name' => '-'];
        }

        $row = DB::connection('tenant')->table('companies')->first($cols);

        return [
            'ruc' => ($row && !empty($row->number)) ? (string) $row->number : '-',
            'name' => ($row && !empty($row->name)) ? (string) $row->name : '-',
        ];
    }

    private function resolveEstablishmentLabel(?int $establishmentId): string
    {
        if ($establishmentId === null || !$this->hasTable('establishments')) {
            return 'Todas las sedes';
        }

        $desc = DB::connection('tenant')->table('establishments')->where('id', $establishmentId)->value('description');

        return $desc ? (string) $desc : ('Sede #' . $establishmentId);
    }

    private function formatPeriodLabel(string $dateStart, string $dateEnd): string
    {
        return Carbon::parse($dateStart)->format('d/m/Y') . ' al ' . Carbon::parse($dateEnd)->format('d/m/Y');
    }

    private function formatParentNumber(object $row, string $mode): string
    {
        if ($mode === 'series_number') {
            return trim(((string) ($row->series ?? '')) . '-' . ((string) ($row->number ?? '')), '-');
        }
        if ($mode === 'sale_note') {
            $series = (string) ($row->series ?? $row->prefix ?? 'NV');
            $num = (string) ($row->number ?? $row->parent_id ?? '');

            return trim($series . '-' . $num, '-');
        }
        if ($mode === 'prefix_id') {
            $prefix = (string) ($row->prefix ?? 'DOC');

            return trim($prefix . '-' . ($row->parent_id ?? ''), '-');
        }
        if ($mode === 'number') {
            return trim((string) ($row->number ?? ('#' . ($row->parent_id ?? ''))));
        }

        return 'ST-' . ($row->parent_id ?? '');
    }

    private function convertAmount(float $amount, string $sourceCurrency, float $rate, string $targetCurrency): float
    {
        if ($sourceCurrency === $targetCurrency) {
            return $amount;
        }
        $safeRate = $rate > 0 ? $rate : 1.0;
        if ($sourceCurrency === 'USD' && $targetCurrency === 'PEN') {
            return $amount * $safeRate;
        }
        if ($sourceCurrency === 'PEN' && $targetCurrency === 'USD') {
            return $amount / $safeRate;
        }

        return $amount;
    }

    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return trim((string) $v);
            }
        }

        return null;
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

    private function resetSchemaCaches(): void
    {
        $this->tableExistsCache = [];
        $this->columnExistsCache = [];
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

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnExistsCache)) {
            return $this->columnExistsCache[$key];
        }
        if (!$this->hasTable($table)) {
            return $this->columnExistsCache[$key] = false;
        }

        try {
            return $this->columnExistsCache[$key] = DB::connection('tenant')->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return $this->columnExistsCache[$key] = false;
        }
    }
}
