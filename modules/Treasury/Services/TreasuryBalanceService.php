<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\ExchangeRate;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Pos\Models\CashTransaction;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use Modules\Treasury\Models\TreasuryMovement;

/**
 * Single source of truth for cash and bank account balances (Decision D3).
 *
 * Formula:
 *   balance = initial_balance + global_payments (inflows - outflows) + active treasury_movements (direction * amount)
 *
 * FinanceTrait::getBalanceByCash() and FinanceTrait::getBalanceByBankAcounts() delegate here
 * so Finance -> Balance and Treasury -> Summary always produce consistent results.
 */
class TreasuryBalanceService
{
    public const ACCEPTED_STATE_TYPES = ['01', '03', '05', '07', '13'];

    public const ACCOUNT_TYPE_LABELS = [
        'checking' => 'Cuenta corriente',
        'savings' => 'Cuenta de ahorros',
        'detraction_bn' => 'Detracciones Banco de la Nación',
        'card' => 'Tarjeta',
        'other' => 'Otra',
    ];

    /** @var array<string, bool> */
    private $tableExistsCache = [];

    public function getBalanceByCash(
        $cash,
        ?float $treasuryNetPen = null,
        ?string $dateStart = null,
        ?string $dateEnd = null
    ): array {
        $document_payment = $this->getSumPayment($cash, DocumentPayment::class);
        $expense_payment = $this->getSumPayment($cash, ExpensePayment::class);
        $sale_note_payment = $this->getSumPayment($cash, SaleNotePayment::class);
        $purchase_payment = $this->getSumPayment($cash, PurchasePayment::class);
        $quotation_payment = $this->getSumPayment($cash, QuotationPayment::class);
        $contract_payment = $this->getSumPayment($cash, ContractPayment::class);
        $income_payment = $this->getSumPayment($cash, IncomePayment::class);
        $cash_pos = $this->getSumPaymentCashPos($cash, CashTransaction::class);
        $technical_service_payment = $this->getSumPayment($cash, TechnicalServicePayment::class);

        $treasuryNet = $treasuryNetPen !== null
            ? $treasuryNetPen
            : $this->getTreasuryNetPenForDestinationType(Cash::class, $dateStart, $dateEnd);

        $entry = $document_payment + $sale_note_payment + $quotation_payment + $contract_payment + $income_payment + $cash_pos + $technical_service_payment;
        $egress = $expense_payment + $purchase_payment;

        $balance = $entry - $egress + $treasuryNet;

        return [
            'id' => 'cash',
            'description' => 'CAJA GENERAL',
            'expense_payment' => $this->formatAmount($expense_payment),
            'sale_note_payment' => $this->formatAmount($sale_note_payment),
            'quotation_payment' => $this->formatAmount($quotation_payment),
            'contract_payment' => $this->formatAmount($contract_payment),
            'income_payment' => $this->formatAmount($income_payment + $cash_pos),
            'document_payment' => $this->formatAmount($document_payment),
            'purchase_payment' => $this->formatAmount($purchase_payment),
            'technical_service_payment' => $this->formatAmount($technical_service_payment),
            'balance' => $this->formatAmount($balance),
        ];
    }

    public function getBalanceByBankAccounts(
        $bank_accounts,
        ?array $treasuryNetPenByAccount = null,
        ?string $dateStart = null,
        ?string $dateEnd = null
    ): Collection {
        $netByAccount = $treasuryNetPenByAccount !== null
            ? $treasuryNetPenByAccount
            : $this->getTreasuryNetPenByAccountIds(
                $bank_accounts->pluck('id')->filter()->all(),
                $dateStart,
                $dateEnd
            );

        return $bank_accounts->map(function ($row) use ($netByAccount) {
            $document_payment = $this->getSumPayment($row->global_destination, DocumentPayment::class);
            $expense_payment = $this->getSumPayment($row->global_destination, ExpensePayment::class);
            $sale_note_payment = $this->getSumPayment($row->global_destination, SaleNotePayment::class);
            $purchase_payment = $this->getSumPayment($row->global_destination, PurchasePayment::class);
            $quotation_payment = $this->getSumPayment($row->global_destination, QuotationPayment::class);
            $contract_payment = $this->getSumPayment($row->global_destination, ContractPayment::class);
            $income_payment = $this->getSumPayment($row->global_destination, IncomePayment::class);
            $technical_service_payment = $this->getSumPayment($row->global_destination, TechnicalServicePayment::class);

            $treasuryNet = (float) ($netByAccount[$row->id] ?? 0.0);

            $entry = $document_payment + $sale_note_payment + $quotation_payment + $contract_payment + $income_payment + $technical_service_payment;
            $egress = $expense_payment + $purchase_payment;
            $balance = $row->initial_balance + $entry - $egress + $treasuryNet;

            return [
                'id' => $row->id,
                'description' => "{$row->bank->description} - {$row->currency_type_id} - {$row->description}",
                'expense_payment' => $this->formatAmount($expense_payment),
                'sale_note_payment' => $this->formatAmount($sale_note_payment),
                'quotation_payment' => $this->formatAmount($quotation_payment),
                'contract_payment' => $this->formatAmount($contract_payment),
                'document_payment' => $this->formatAmount($document_payment),
                'purchase_payment' => $this->formatAmount($purchase_payment),
                'income_payment' => $this->formatAmount($income_payment),
                'technical_service_payment' => $this->formatAmount($technical_service_payment),
                'balance' => $this->formatAmount($balance),
            ];
        });
    }

    public function getSumPaymentCashPos($record, string $model): float
    {
        return (float) $record->where('payment_type', $model)->sum(function ($row) {
            return $row->payment->payment;
        });
    }

    public function getSumPayment($record, string $model): float
    {
        $total = 0.0;
        $creditNotesByDocument = [];

        foreach ($record->where('payment_type', $model)->get() as $row) {
            $payment = $row->payment;
            $associated = $payment ? $payment->associated_record_payment : null;

            if (!$associated) {
                continue;
            }

            $total += $this->calculateTotalCurrencyType($associated, $payment->payment);

            if ($row->instance_type === 'document') {
                $key = $associated->getKey();
                if (!array_key_exists($key, $creditNotesByDocument)) {
                    $creditNotesByDocument[$key] = $this->getTotalCreditNotes($associated);
                }
            }
        }

        return $total - array_sum($creditNotesByDocument);
    }

    public function getTotalCreditNotes($record): float
    {
        if (!isset($record->affected_documents)) {
            return 0.0;
        }

        $credit_notes = $record->affected_documents->where('note_type', 'credit');

        $total_credit_notes = $credit_notes->sum(function ($note) {
            if (!$note->document) {
                return 0;
            }

            if (in_array($note->document->state_type_id, self::ACCEPTED_STATE_TYPES)) {
                return $this->calculateTotalCurrencyType($note->document, $note->document->total);
            }

            return 0;
        });

        return (float) $total_credit_notes;
    }

    public function calculateTotalCurrencyType($record, $payment)
    {
        return ($record->currency_type_id === 'USD') ? $payment * $record->exchange_rate_sale : $payment;
    }

    /**
     * Wraps a loaded GlobalPayment Collection into a query-compatible adapter that supports
     * both ->where('payment_type', $model)->get() and ->where('payment_type', $model)->sum($cb).
     */
    public function wrapGlobalPayments(Collection $rows): object
    {
        return new class($rows) {
            private $items;

            public function __construct(Collection $items)
            {
                $this->items = $items;
            }

            public function where(string $key, $value): self
            {
                return new self($this->items->where($key, $value)->values());
            }

            public function get(): Collection
            {
                return $this->items;
            }

            public function sum($callback)
            {
                return $this->items->sum($callback);
            }
        };
    }

    /**
     * Builds the Treasury Phase 1 Summary payload using SQL-level aggregation
     * (constant O(1) queries regardless of payment volume, zero Eloquent N+1).
     *
     * Never calls auth() internally; accepts explicit filter parameters only.
     */
    public function getSummary(array $filters = []): array
    {
        $establishmentId = !empty($filters['establishment_id']) ? (int) $filters['establishment_id'] : null;
        $currencyTypeId = !empty($filters['currency_type_id']) ? (string) $filters['currency_type_id'] : null;
        $dateStart = !empty($filters['date_start']) ? (string) $filters['date_start'] : null;
        $dateEnd = !empty($filters['date_end']) ? (string) $filters['date_end'] : null;

        $exchangeRatePurchase = $this->resolveLatestExchangeRatePurchase();

        $bankAccountsQuery = BankAccount::with(['bank', 'establishment'])->orderBy('id');
        if ($establishmentId !== null) {
            $bankAccountsQuery->where(function ($q) use ($establishmentId) {
                $q->where('establishment_id', $establishmentId)->orWhereNull('establishment_id');
            });
        }
        if ($currencyTypeId !== null) {
            $bankAccountsQuery->where('currency_type_id', $currencyTypeId);
        }
        $bankAccounts = $bankAccountsQuery->get();

        $bankAccountIds = $bankAccounts->pluck('id')->all();
        $bankAccountCurrencyMap = $bankAccounts->pluck('currency_type_id', 'id')->all();

        $bankGlobalAgg = $this->aggregateGlobalPaymentsByDestination(
            BankAccount::class,
            $bankAccountIds,
            $dateStart,
            $dateEnd,
            $bankAccountCurrencyMap
        );
        $bankTreasuryByAccount = $this->loadTreasuryAggregatesByDestination(
            BankAccount::class,
            $bankAccountIds,
            $dateStart,
            $dateEnd,
            $establishmentId
        );

        $accountRows = [];
        $banksPenTotal = 0.0;
        $banksUsdTotal = 0.0;
        $banksUsdInPenTotal = 0.0;
        $detractionBnBalance = 0.0;
        $detractionBnCount = 0;
        $negativeAccounts = [];
        $missingExchangeRate = false;
        $unconvertedCurrencies = [];

        foreach ($bankAccounts as $account) {
            $agg = $bankGlobalAgg[$account->id] ?? $this->emptyDestinationAggregate();
            $treasuryAgg = $bankTreasuryByAccount[$account->id] ?? ['net_currency' => 0.0, 'net_pen' => 0.0];

            $entryPen = $agg['document_payment']
                + $agg['sale_note_payment']
                + $agg['quotation_payment']
                + $agg['contract_payment']
                + $agg['income_payment']
                + $agg['technical_service_payment'];
            $egressPen = $agg['expense_payment'] + $agg['purchase_payment'];
            $financeBalance = round((float) $account->initial_balance + $entryPen - $egressPen + $treasuryAgg['net_pen'], 2);

            if ($account->currency_type_id === 'PEN') {
                $balanceCurrency = $financeBalance;
                $balancePen = $financeBalance;
                $banksPenTotal += $balanceCurrency;
            } elseif ($account->currency_type_id === 'USD') {
                $balanceCurrency = round((float) $account->initial_balance + $agg['native_net'] + $treasuryAgg['net_currency'], 2);
                $banksUsdTotal += $balanceCurrency;

                if ($exchangeRatePurchase > 0) {
                    $balancePen = round($balanceCurrency * $exchangeRatePurchase, 2);
                    $banksUsdInPenTotal += $balancePen;
                } else {
                    $balancePen = null;
                    $missingExchangeRate = true;
                }
            } else {
                $balanceCurrency = round((float) $account->initial_balance + $agg['native_net'] + $treasuryAgg['net_currency'], 2);
                $balancePen = null;
                $unconvertedCurrencies[$account->currency_type_id] = true;
            }

            $accountType = $account->account_type ?: 'checking';
            if ($accountType === 'detraction_bn') {
                if ($balancePen !== null) {
                    $detractionBnBalance += $balancePen;
                }
                $detractionBnCount++;
            }

            $bankName = $account->bank ? $account->bank->description : '-';
            $fullDescription = "{$bankName} - {$account->currency_type_id} - {$account->description}";

            $accountRows[] = [
                'id' => $account->id,
                'bank_id' => $account->bank_id,
                'bank_description' => $bankName,
                'description' => $account->description,
                'full_description' => $fullDescription,
                'number' => $account->number,
                'cci' => $account->cci,
                'currency_type_id' => $account->currency_type_id,
                'account_type' => $accountType,
                'account_type_label' => self::ACCOUNT_TYPE_LABELS[$accountType] ?? $accountType,
                'pcge_account' => $account->pcge_account,
                'itf_exempt' => (bool) $account->itf_exempt,
                'establishment_id' => $account->establishment_id,
                'establishment_description' => $account->establishment ? $account->establishment->description : 'Todas las sedes',
                'initial_balance' => $this->formatAmount((float) $account->initial_balance),
                'treasury_net_currency' => $this->formatAmount($treasuryAgg['net_currency']),
                'treasury_net_pen' => $this->formatAmount($treasuryAgg['net_pen']),
                'balance_currency' => $this->formatAmount($balanceCurrency),
                'balance_pen' => $balancePen !== null ? $this->formatAmount($balancePen) : null,
                'finance_balance' => $this->formatAmount($financeBalance),
                'is_negative' => $balanceCurrency < 0,
                'breakdown' => [
                    'document_payment' => $this->formatAmount($agg['document_payment']),
                    'sale_note_payment' => $this->formatAmount($agg['sale_note_payment']),
                    'quotation_payment' => $this->formatAmount($agg['quotation_payment']),
                    'contract_payment' => $this->formatAmount($agg['contract_payment']),
                    'income_payment' => $this->formatAmount($agg['income_payment']),
                    'technical_service_payment' => $this->formatAmount($agg['technical_service_payment']),
                    'purchase_payment' => $this->formatAmount($agg['purchase_payment']),
                    'expense_payment' => $this->formatAmount($agg['expense_payment']),
                ],
            ];

            if ($balanceCurrency < 0) {
                $negativeAccounts[] = [
                    'type' => 'bank_account',
                    'id' => $account->id,
                    'description' => $fullDescription,
                    'currency_type_id' => $account->currency_type_id,
                    'balance' => $this->formatAmount($balanceCurrency),
                ];
            }
        }

        // Cash boxes (always PEN). Filter cash boxes by user.establishment_id when establishment filter is active.
        $includeCash = ($currencyTypeId === null || $currencyTypeId === 'PEN');
        $cashGeneral = null;
        $openCashes = [];
        $cashPenTotal = 0.0;

        if ($includeCash) {
            $scopedCashIds = null;
            if ($establishmentId !== null && $this->hasTable('cash') && $this->hasTable('users')) {
                $scopedCashIds = DB::connection('tenant')
                    ->table('cash')
                    ->join('users', 'users.id', '=', 'cash.user_id')
                    ->where('users.establishment_id', $establishmentId)
                    ->pluck('cash.id')
                    ->map('intval')
                    ->all();
            }

            $cashGlobalAggById = ($scopedCashIds !== null && empty($scopedCashIds))
                ? []
                : $this->aggregateGlobalPaymentsByDestination(
                    Cash::class,
                    $scopedCashIds ?? [],
                    $dateStart,
                    $dateEnd,
                    []
                );

            $cashTreasuryById = ($scopedCashIds !== null && empty($scopedCashIds))
                ? []
                : $this->loadTreasuryAggregatesByDestination(
                    Cash::class,
                    $scopedCashIds ?? [],
                    $dateStart,
                    $dateEnd,
                    $establishmentId
                );

            $cashTotals = $this->emptyDestinationAggregate();
            foreach ($cashGlobalAggById as $agg) {
                foreach ($cashTotals as $key => $val) {
                    $cashTotals[$key] += $agg[$key];
                }
            }
            $totalCashTreasuryPen = (float) array_sum(array_column($cashTreasuryById, 'net_pen'));

            $cashEntry = $cashTotals['document_payment']
                + $cashTotals['sale_note_payment']
                + $cashTotals['quotation_payment']
                + $cashTotals['contract_payment']
                + $cashTotals['income_payment']
                + $cashTotals['cash_pos']
                + $cashTotals['technical_service_payment'];
            $cashEgress = $cashTotals['expense_payment'] + $cashTotals['purchase_payment'];
            $cashPenTotal = round($cashEntry - $cashEgress + $totalCashTreasuryPen, 2);

            $cashGeneral = [
                'id' => 'cash',
                'description' => 'CAJA GENERAL',
                'expense_payment' => $this->formatAmount($cashTotals['expense_payment']),
                'sale_note_payment' => $this->formatAmount($cashTotals['sale_note_payment']),
                'quotation_payment' => $this->formatAmount($cashTotals['quotation_payment']),
                'contract_payment' => $this->formatAmount($cashTotals['contract_payment']),
                'income_payment' => $this->formatAmount($cashTotals['income_payment'] + $cashTotals['cash_pos']),
                'document_payment' => $this->formatAmount($cashTotals['document_payment']),
                'purchase_payment' => $this->formatAmount($cashTotals['purchase_payment']),
                'technical_service_payment' => $this->formatAmount($cashTotals['technical_service_payment']),
                'balance' => $this->formatAmount($cashPenTotal),
            ];

            if ($this->hasTable('cash')) {
                $openCashQuery = Cash::with('user')
                    ->where('state', true)
                    ->orderBy('date_opening', 'desc')
                    ->orderBy('id', 'desc');

                if ($scopedCashIds !== null) {
                    $openCashQuery->whereIn('id', empty($scopedCashIds) ? [-1] : $scopedCashIds);
                }

                foreach ($openCashQuery->get() as $cashModel) {
                    $cAgg = $cashGlobalAggById[$cashModel->id] ?? $this->emptyDestinationAggregate();
                    $cTreasuryNet = (float) ($cashTreasuryById[$cashModel->id]['net_pen'] ?? 0.0);

                    $cEntry = $cAgg['document_payment']
                        + $cAgg['sale_note_payment']
                        + $cAgg['quotation_payment']
                        + $cAgg['contract_payment']
                        + $cAgg['income_payment']
                        + $cAgg['cash_pos']
                        + $cAgg['technical_service_payment'];
                    $cEgress = $cAgg['expense_payment'] + $cAgg['purchase_payment'];
                    $cashBalanceValue = round($cEntry - $cEgress + $cTreasuryNet, 2);

                    $cashDescription = $cashModel->reference_number
                        ? "CAJA GENERAL - {$cashModel->reference_number}"
                        : 'CAJA GENERAL';

                    $openCashes[] = [
                        'id' => $cashModel->id,
                        'user_id' => $cashModel->user_id,
                        'user_name' => $cashModel->user ? $cashModel->user->name : ('Usuario #' . $cashModel->user_id),
                        'reference_number' => $cashModel->reference_number,
                        'description' => $cashDescription,
                        'date_opening' => $cashModel->date_opening,
                        'time_opening' => $cashModel->time_opening,
                        'currency_type_id' => 'PEN',
                        'beginning_balance' => $this->formatAmount((float) $cashModel->beginning_balance),
                        'balance' => $this->formatAmount($cashBalanceValue),
                        'is_negative' => $cashBalanceValue < 0,
                    ];

                    if ($cashBalanceValue < 0) {
                        $negativeAccounts[] = [
                            'type' => 'cash',
                            'id' => $cashModel->id,
                            'description' => $cashDescription,
                            'currency_type_id' => 'PEN',
                            'balance' => $this->formatAmount($cashBalanceValue),
                        ];
                    }
                }
            }
        }

        $consolidatedPen = round($banksPenTotal + $banksUsdInPenTotal + $cashPenTotal, 2);

        return [
            'bank_accounts' => $accountRows,
            'cash_general' => $cashGeneral,
            'open_cashes' => $openCashes,
            'totals' => [
                'consolidated_pen' => $this->formatAmount($consolidatedPen),
                'banks_pen' => $this->formatAmount($banksPenTotal),
                'banks_usd' => $this->formatAmount($banksUsdTotal),
                'banks_usd_in_pen' => $this->formatAmount($banksUsdInPenTotal),
                'cash_pen' => $this->formatAmount($cashPenTotal),
                'open_cash_count' => count($openCashes),
                'detraction_bn_balance' => $this->formatAmount($detractionBnBalance),
                'detraction_bn_count' => $detractionBnCount,
                'exchange_rate_purchase' => $exchangeRatePurchase > 0 ? round($exchangeRatePurchase, 4) : null,
                'missing_exchange_rate' => $missingExchangeRate,
                'unconverted_currencies' => array_keys($unconvertedCurrencies),
            ],
            'alerts' => [
                'negative_accounts' => $negativeAccounts,
                'overdue_detractions' => [],
                'missing_exchange_rate' => $missingExchangeRate,
            ],
        ];
    }

    /**
     * Balance of ONE bank account in its own currency as of $dateEnd (inclusive; null = no date bound).
     * Same formula as getSummary() (initial_balance + global payments + active treasury movements), used by the
     * bank reconciliation to compare against the statement closing balance.
     *
     * @return array{currency_type_id: string, balance: float}
     */
    public function getBankAccountBalanceAsOf(int $accountId, ?string $dateEnd = null): array
    {
        $account = BankAccount::query()->whereKey($accountId)->first();
        if (!$account) {
            throw new \InvalidArgumentException('Bank account not found: ' . $accountId);
        }

        $currency = (string) $account->currency_type_id;
        $agg = $this->aggregateGlobalPaymentsByDestination(
            BankAccount::class,
            [$accountId],
            null,
            $dateEnd,
            [$accountId => $currency],
            true
        )[$accountId] ?? $this->emptyDestinationAggregate();

        $treasury = $this->loadTreasuryAggregatesByDestination(
            BankAccount::class,
            [$accountId],
            null,
            $dateEnd,
            null
        )[$accountId] ?? ['net_currency' => 0.0, 'net_pen' => 0.0];

        if ($currency === 'PEN') {
            $entry = $agg['document_payment'] + $agg['sale_note_payment'] + $agg['quotation_payment']
                + $agg['contract_payment'] + $agg['income_payment'] + $agg['technical_service_payment'];
            $egress = $agg['expense_payment'] + $agg['purchase_payment'];
            $balance = (float) $account->initial_balance + $entry - $egress + $treasury['net_pen'];
        } else {
            $balance = (float) $account->initial_balance + $agg['native_net'] + $treasury['net_currency'];
        }

        return ['currency_type_id' => $currency, 'balance' => round($balance, 2)];
    }

    /**
     * Credit-note deductions that getBankAccountBalanceAsOf() applies to ONE bank account, itemized.
     *
     * Same rules as the aggregate: a credit note is deducted once per document that has accepted payments into the
     * account up to $dateEnd, only while the credit note document is in an accepted state and (as-of semantics) was
     * issued on or before $dateEnd. The amount is signed (negative: it reduces the books) and expressed in the
     * account currency exactly as the balance computes it. These items never appear on a bank statement.
     *
     * @return array<int, array{key: string, credit_note_document_id: int, document_id: int, date: string, amount: float, number: string, document_number: string, currency_type_id: string}>
     */
    public function getBankAccountCreditNoteAdjustments(int $accountId, ?string $dateEnd = null): array
    {
        if (!$this->hasTable('global_payments') || !$this->hasTable('document_payments')
            || !$this->hasTable('documents') || !$this->hasTable('notes')) {
            return [];
        }

        $account = BankAccount::query()->whereKey($accountId)->first();
        if (!$account) {
            return [];
        }
        $targetCurrency = (string) $account->currency_type_id;

        $docQuery = DB::connection('tenant')
            ->table('global_payments as gp')
            ->join('document_payments as p', 'p.id', '=', 'gp.payment_id')
            ->join('documents as d', 'd.id', '=', 'p.document_id')
            ->where('gp.destination_type', BankAccount::class)
            ->where('gp.payment_type', DocumentPayment::class)
            ->where('gp.destination_id', $accountId)
            ->whereIn('d.state_type_id', self::ACCEPTED_STATE_TYPES);
        if ($dateEnd) {
            $docQuery->where('p.date_of_payment', '<=', $dateEnd);
        }
        $docIds = $docQuery->distinct()->pluck('d.id')->map(function ($id) {
            return (int) $id;
        })->all();
        if (empty($docIds)) {
            return [];
        }

        $query = DB::connection('tenant')
            ->table('notes as n')
            ->join('documents as nd', 'nd.id', '=', 'n.document_id')
            ->join('documents as d', 'd.id', '=', 'n.affected_document_id')
            ->where('n.note_type', 'credit')
            ->whereIn('n.affected_document_id', $docIds)
            ->whereIn('nd.state_type_id', self::ACCEPTED_STATE_TYPES)
            ->orderBy('nd.date_of_issue')
            ->orderBy('nd.id');
        if ($dateEnd) {
            $query->whereDate('nd.date_of_issue', '<=', $dateEnd);
        }

        $rows = $query->get([
            'n.affected_document_id',
            'nd.id as nd_id',
            'nd.series as nd_series',
            'nd.number as nd_number',
            'nd.date_of_issue',
            'nd.total',
            'nd.currency_type_id',
            'nd.exchange_rate_sale',
            'd.series as d_series',
            'd.number as d_number',
        ]);

        $items = [];
        foreach ($rows as $row) {
            $amount = $this->convertAmountToAccountCurrency(
                (float) $row->total,
                (string) ($row->currency_type_id ?? 'PEN'),
                (float) ($row->exchange_rate_sale ?? 1.0),
                $targetCurrency
            );
            $items[] = [
                'key' => 'credit_note_adjustment:' . (int) $row->nd_id . ':' . (int) $row->affected_document_id,
                'credit_note_document_id' => (int) $row->nd_id,
                'document_id' => (int) $row->affected_document_id,
                'date' => substr((string) $row->date_of_issue, 0, 10),
                'amount' => -round($amount, 2),
                'number' => trim($row->nd_series . '-' . $row->nd_number, '-'),
                'document_number' => trim($row->d_series . '-' . $row->d_number, '-'),
                'currency_type_id' => $targetCurrency,
            ];
        }

        return $items;
    }

    private function emptyDestinationAggregate(): array
    {
        return [
            'document_payment' => 0.0,
            'sale_note_payment' => 0.0,
            'quotation_payment' => 0.0,
            'contract_payment' => 0.0,
            'income_payment' => 0.0,
            'cash_pos' => 0.0,
            'technical_service_payment' => 0.0,
            'purchase_payment' => 0.0,
            'expense_payment' => 0.0,
            'native_net' => 0.0,
        ];
    }

    /**
     * Aggregates global_payments per destination_id directly in SQL across all 9 payment tables,
     * applying accepted state filtering, changed=false filtering, optional date bounds,
     * and per-document credit note deduplication without hydrating Eloquent graphs.
     */
    private function aggregateGlobalPaymentsByDestination(
        string $destinationType,
        array $destinationIds,
        ?string $dateStart,
        ?string $dateEnd,
        array $accountCurrencyMap,
        bool $boundCreditNotesByIssueDate = false
    ): array {
        if (!$this->hasTable('global_payments')) {
            return [];
        }
        if ($destinationType === BankAccount::class && empty($destinationIds)) {
            return [];
        }

        $result = [];
        $ensure = function (int $destId) use (&$result): void {
            if (!isset($result[$destId])) {
                $result[$destId] = $this->emptyDestinationAggregate();
            }
        };

        // 1. Documents (requires per-document credit note deduplication per destination)
        if ($this->hasTable('document_payments') && $this->hasTable('documents')) {
            $docQuery = DB::connection('tenant')
                ->table('global_payments as gp')
                ->join('document_payments as p', 'p.id', '=', 'gp.payment_id')
                ->join('documents as d', 'd.id', '=', 'p.document_id')
                ->where('gp.destination_type', $destinationType)
                ->where('gp.payment_type', DocumentPayment::class)
                ->whereIn('d.state_type_id', self::ACCEPTED_STATE_TYPES)
                ->select(
                    'gp.destination_id',
                    'd.id as document_id',
                    'd.currency_type_id',
                    'd.exchange_rate_sale',
                    DB::raw('SUM(p.payment) as sum_raw')
                )
                ->groupBy('gp.destination_id', 'd.id', 'd.currency_type_id', 'd.exchange_rate_sale');

            if (!empty($destinationIds)) {
                $docQuery->whereIn('gp.destination_id', $destinationIds);
            }
            if ($dateStart) {
                $docQuery->where('p.date_of_payment', '>=', $dateStart);
            }
            if ($dateEnd) {
                $docQuery->where('p.date_of_payment', '<=', $dateEnd);
            }

            $docRows = $docQuery->get();
            $affectedDocIds = [];

            foreach ($docRows as $row) {
                $destId = (int) $row->destination_id;
                $docId = (int) $row->document_id;
                $ensure($destId);
                $affectedDocIds[$docId] = true;

                $raw = (float) $row->sum_raw;
                $currency = (string) ($row->currency_type_id ?? 'PEN');
                $rate = (float) ($row->exchange_rate_sale ?? 1.0);
                $pen = ($currency === 'USD') ? ($raw * $rate) : $raw;

                $result[$destId]['document_payment'] += $pen;

                $targetCurrency = $accountCurrencyMap[$destId] ?? 'PEN';
                $result[$destId]['native_net'] += $this->convertAmountToAccountCurrency($raw, $currency, $rate, $targetCurrency);
            }

            // Deduct credit notes ONCE per (destination_id, document_id)
            if (!empty($affectedDocIds) && $this->hasTable('notes')) {
                $creditNoteQuery = DB::connection('tenant')
                    ->table('notes as n')
                    ->join('documents as nd', 'nd.id', '=', 'n.document_id')
                    ->where('n.note_type', 'credit')
                    ->whereIn('n.affected_document_id', array_keys($affectedDocIds))
                    ->whereIn('nd.state_type_id', self::ACCEPTED_STATE_TYPES)
                    ->select(
                        'n.affected_document_id',
                        'nd.total',
                        'nd.currency_type_id',
                        'nd.exchange_rate_sale'
                    );

                // As-of balances (bank reconciliation only) count a credit note once it has been issued. The current
                // balance keeps its historical behaviour: not date-bounded.
                if ($boundCreditNotesByIssueDate && $dateEnd) {
                    $creditNoteQuery->whereDate('nd.date_of_issue', '<=', $dateEnd);
                }

                $creditNoteRows = $creditNoteQuery->get();

                $creditNotesByDoc = [];
                foreach ($creditNoteRows as $cn) {
                    $creditNotesByDoc[(int) $cn->affected_document_id][] = [
                        'total' => (float) $cn->total,
                        'currency' => (string) ($cn->currency_type_id ?? 'PEN'),
                        'rate' => (float) ($cn->exchange_rate_sale ?? 1.0),
                    ];
                }

                $deducted = [];
                foreach ($docRows as $row) {
                    $destId = (int) $row->destination_id;
                    $docId = (int) $row->document_id;
                    $pairKey = $destId . ':' . $docId;
                    if (isset($deducted[$pairKey]) || empty($creditNotesByDoc[$docId])) {
                        continue;
                    }
                    $deducted[$pairKey] = true;
                    $targetCurrency = $accountCurrencyMap[$destId] ?? 'PEN';

                    foreach ($creditNotesByDoc[$docId] as $cn) {
                        $cnPen = ($cn['currency'] === 'USD') ? ($cn['total'] * $cn['rate']) : $cn['total'];
                        $result[$destId]['document_payment'] -= $cnPen;
                        $result[$destId]['native_net'] -= $this->convertAmountToAccountCurrency(
                            $cn['total'],
                            $cn['currency'],
                            $cn['rate'],
                            $targetCurrency
                        );
                    }
                }
            }
        }

        // 2. Standard inflow & outflow payment tables (1 SQL query per table)
        $specs = [
            ['key' => 'sale_note_payment', 'class' => SaleNotePayment::class, 'pay' => 'sale_note_payments', 'fk' => 'sale_note_id', 'parent' => 'sale_notes', 'sign' => 1, 'has_state' => true, 'has_changed' => true, 'has_currency' => true],
            ['key' => 'quotation_payment', 'class' => QuotationPayment::class, 'pay' => 'quotation_payments', 'fk' => 'quotation_id', 'parent' => 'quotations', 'sign' => 1, 'has_state' => true, 'has_changed' => true, 'has_currency' => true],
            ['key' => 'contract_payment', 'class' => ContractPayment::class, 'pay' => 'contract_payments', 'fk' => 'contract_id', 'parent' => 'contracts', 'sign' => 1, 'has_state' => true, 'has_changed' => true, 'has_currency' => true],
            ['key' => 'income_payment', 'class' => IncomePayment::class, 'pay' => 'income_payments', 'fk' => 'income_id', 'parent' => 'incomes', 'sign' => 1, 'has_state' => true, 'has_changed' => false, 'has_currency' => true],
            ['key' => 'technical_service_payment', 'class' => TechnicalServicePayment::class, 'pay' => 'technical_service_payments', 'fk' => 'technical_service_id', 'parent' => 'technical_services', 'sign' => 1, 'has_state' => false, 'has_changed' => false, 'has_currency' => false],
            ['key' => 'purchase_payment', 'class' => PurchasePayment::class, 'pay' => 'purchase_payments', 'fk' => 'purchase_id', 'parent' => 'purchases', 'sign' => -1, 'has_state' => true, 'has_changed' => false, 'has_currency' => true],
            ['key' => 'expense_payment', 'class' => ExpensePayment::class, 'pay' => 'expense_payments', 'fk' => 'expense_id', 'parent' => 'expenses', 'sign' => -1, 'has_state' => true, 'has_changed' => false, 'has_currency' => true],
        ];

        foreach ($specs as $spec) {
            if (!$this->hasTable($spec['pay']) || !$this->hasTable($spec['parent'])) {
                continue;
            }

            $q = DB::connection('tenant')
                ->table('global_payments as gp')
                ->join($spec['pay'] . ' as p', 'p.id', '=', 'gp.payment_id')
                ->join($spec['parent'] . ' as par', 'par.id', '=', 'p.' . $spec['fk'])
                ->where('gp.destination_type', $destinationType)
                ->where('gp.payment_type', $spec['class']);

            if ($spec['has_state']) {
                $q->whereIn('par.state_type_id', self::ACCEPTED_STATE_TYPES);
            }
            if ($spec['has_changed']) {
                $q->where('par.changed', false);
            }
            if (!empty($destinationIds)) {
                $q->whereIn('gp.destination_id', $destinationIds);
            }
            if ($dateStart) {
                $q->where('p.date_of_payment', '>=', $dateStart);
            }
            if ($dateEnd) {
                $q->where('p.date_of_payment', '<=', $dateEnd);
            }

            if ($spec['has_currency']) {
                $rows = $q->select(
                    'gp.destination_id',
                    'par.currency_type_id',
                    'par.exchange_rate_sale',
                    DB::raw('SUM(p.payment) as sum_raw')
                )
                    ->groupBy('gp.destination_id', 'par.currency_type_id', 'par.exchange_rate_sale')
                    ->get();
            } else {
                $rows = $q->select(
                    'gp.destination_id',
                    DB::raw("'PEN' as currency_type_id"),
                    DB::raw('1 as exchange_rate_sale'),
                    DB::raw('SUM(p.payment) as sum_raw')
                )
                    ->groupBy('gp.destination_id')
                    ->get();
            }

            foreach ($rows as $row) {
                $destId = (int) $row->destination_id;
                $ensure($destId);

                $raw = (float) $row->sum_raw;
                $currency = (string) ($row->currency_type_id ?? 'PEN');
                $rate = (float) ($row->exchange_rate_sale ?? 1.0);
                $pen = ($currency === 'USD') ? ($raw * $rate) : $raw;

                $result[$destId][$spec['key']] += $pen;

                $targetCurrency = $accountCurrencyMap[$destId] ?? 'PEN';
                $result[$destId]['native_net'] += $spec['sign'] * $this->convertAmountToAccountCurrency($raw, $currency, $rate, $targetCurrency);
            }
        }

        // 3. CashTransaction (Cash POS only; summed raw without currency conversion)
        if ($destinationType === Cash::class && $this->hasTable('cash_transactions')) {
            $txQuery = DB::connection('tenant')
                ->table('global_payments as gp')
                ->join('cash_transactions as ct', 'ct.id', '=', 'gp.payment_id')
                ->where('gp.destination_type', Cash::class)
                ->where('gp.payment_type', CashTransaction::class)
                ->select('gp.destination_id', DB::raw('SUM(ct.payment) as sum_raw'))
                ->groupBy('gp.destination_id');

            if (!empty($destinationIds)) {
                $txQuery->whereIn('gp.destination_id', $destinationIds);
            }
            if ($dateStart) {
                $txQuery->where('ct.date', '>=', $dateStart);
            }
            if ($dateEnd) {
                $txQuery->where('ct.date', '<=', $dateEnd);
            }

            foreach ($txQuery->get() as $row) {
                $destId = (int) $row->destination_id;
                $ensure($destId);
                $raw = (float) $row->sum_raw;
                $result[$destId]['cash_pos'] += $raw;
                $result[$destId]['native_net'] += $raw;
            }
        }

        return $result;
    }

    private function convertAmountToAccountCurrency(
        float $amount,
        string $sourceCurrency,
        float $exchangeRateSale,
        string $targetCurrency
    ): float {
        if ($sourceCurrency === $targetCurrency) {
            return $amount;
        }

        $rate = $exchangeRateSale > 0 ? $exchangeRateSale : 1.0;

        if ($sourceCurrency === 'USD' && $targetCurrency === 'PEN') {
            return $amount * $rate;
        }

        if ($sourceCurrency === 'PEN' && $targetCurrency === 'USD') {
            return $amount / $rate;
        }

        return $amount;
    }

    private function loadTreasuryAggregatesByDestination(
        string $destinationType,
        array $destinationIds,
        ?string $dateStart,
        ?string $dateEnd,
        ?int $establishmentId
    ): array {
        if (!$this->hasTable('treasury_movements')) {
            return [];
        }

        if ($destinationType === BankAccount::class && empty($destinationIds)) {
            return [];
        }

        $query = DB::connection('tenant')
            ->table('treasury_movements')
            ->select(
                'destination_id',
                DB::raw('SUM(direction * amount) as net_currency'),
                DB::raw('SUM(direction * amount_pen) as net_pen')
            )
            ->where('state', TreasuryMovement::STATE_ACTIVE)
            ->where('destination_type', $destinationType);

        if (!empty($destinationIds)) {
            $query->whereIn('destination_id', $destinationIds);
        }
        if ($establishmentId !== null) {
            $query->where('establishment_id', $establishmentId);
        }
        if ($dateStart) {
            $query->where('date', '>=', $dateStart);
        }
        if ($dateEnd) {
            $query->where('date', '<=', $dateEnd);
        }

        $rows = $query->groupBy('destination_id')->get();
        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->destination_id] = [
                'net_currency' => round((float) $row->net_currency, 2),
                'net_pen' => round((float) $row->net_pen, 2),
            ];
        }

        return $result;
    }

    private function getTreasuryNetPenForDestinationType(
        string $destinationType,
        ?string $dateStart = null,
        ?string $dateEnd = null
    ): float {
        if (!$this->hasTable('treasury_movements')) {
            return 0.0;
        }

        $query = DB::connection('tenant')
            ->table('treasury_movements')
            ->where('state', TreasuryMovement::STATE_ACTIVE)
            ->where('destination_type', $destinationType);

        if ($dateStart) {
            $query->where('date', '>=', $dateStart);
        }
        if ($dateEnd) {
            $query->where('date', '<=', $dateEnd);
        }

        $sum = $query->selectRaw('COALESCE(SUM(direction * amount_pen), 0) as net_pen')->value('net_pen');

        return round((float) $sum, 2);
    }

    private function getTreasuryNetPenByAccountIds(
        array $accountIds,
        ?string $dateStart = null,
        ?string $dateEnd = null
    ): array {
        if (empty($accountIds) || !$this->hasTable('treasury_movements')) {
            return [];
        }

        $query = DB::connection('tenant')
            ->table('treasury_movements')
            ->select('destination_id', DB::raw('SUM(direction * amount_pen) as net_pen'))
            ->where('state', TreasuryMovement::STATE_ACTIVE)
            ->where('destination_type', BankAccount::class)
            ->whereIn('destination_id', $accountIds);

        if ($dateStart) {
            $query->where('date', '>=', $dateStart);
        }
        if ($dateEnd) {
            $query->where('date', '<=', $dateEnd);
        }

        $rows = $query->groupBy('destination_id')->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->destination_id] = round((float) $row->net_pen, 2);
        }

        return $map;
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

    private function resolveLatestExchangeRatePurchase(): float
    {
        if (!$this->hasTable('exchange_rates')) {
            return 0.0;
        }

        try {
            $latest = ExchangeRate::orderBy('date', 'desc')->first();
            if (!$latest) {
                return 0.0;
            }

            return (float) ($latest->purchase ?: $latest->sale ?: 0.0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    public function formatAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
