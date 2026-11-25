<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\GlobalPayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Finance\Traits\FinanceTrait;
use Modules\Pos\Models\CashTransaction;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Modules\Finance\Traits\FinanceTrait.
 *
 * Freezes the current baseline behavior (including existing quirks and bugs)
 * before Phase 1 introduces TreasuryBalanceService and delegates balance math.
 */
class FinanceTraitCharacterizationTest extends TestCase
{
    private $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class {
            use FinanceTrait;
        };
    }

    /**
     * Hybrid query-collection double matching both FinanceTrait::getSumPayment()
     * (which calls ->where('payment_type', $model)->get()) and
     * FinanceTrait::getSumPaymentCashPos() (which calls ->where('payment_type', $model)->sum($cb)).
     */
    private function makeHybridDestination(array $rows): object
    {
        return new class(collect($rows)) {
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

    private function makeAssociatedRecord(
        int $id,
        string $currencyTypeId,
        float $exchangeRateSale = 1.0,
        array $affectedDocuments = []
    ): object {
        return new class($id, $currencyTypeId, $exchangeRateSale, collect($affectedDocuments)) {
            private $id;
            public $currency_type_id;
            public $exchange_rate_sale;
            public $affected_documents;

            public function __construct(
                int $id,
                string $currencyTypeId,
                float $exchangeRateSale,
                Collection $affectedDocuments
            ) {
                $this->id = $id;
                $this->currency_type_id = $currencyTypeId;
                $this->exchange_rate_sale = $exchangeRateSale;
                $this->affected_documents = $affectedDocuments;
            }

            public function getKey(): int
            {
                return $this->id;
            }
        };
    }

    private function makeAffectedNote(
        string $noteType,
        string $stateTypeId,
        string $currencyTypeId,
        float $total,
        float $exchangeRateSale = 1.0
    ): object {
        return (object) [
            'note_type' => $noteType,
            'document' => (object) [
                'state_type_id' => $stateTypeId,
                'currency_type_id' => $currencyTypeId,
                'total' => $total,
                'exchange_rate_sale' => $exchangeRateSale,
            ],
        ];
    }

    private function makeGlobalPaymentRow(
        string $paymentType,
        string $instanceType,
        ?float $amount,
        ?object $associatedRecord
    ): object {
        $payment = $amount === null ? null : (object) [
            'payment' => $amount,
            'associated_record_payment' => $associatedRecord,
        ];

        return (object) [
            'payment_type' => $paymentType,
            'instance_type' => $instanceType,
            'payment' => $payment,
        ];
    }

    public function test_calculate_total_currency_type_converts_only_strict_uppercase_usd(): void
    {
        $usd = (object) ['currency_type_id' => 'USD', 'exchange_rate_sale' => 3.75];
        $pen = (object) ['currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75];
        $lowercaseUsd = (object) ['currency_type_id' => 'usd', 'exchange_rate_sale' => 3.75];
        $nullCurrency = (object) ['currency_type_id' => null, 'exchange_rate_sale' => 3.75];
        $eur = (object) ['currency_type_id' => 'EUR', 'exchange_rate_sale' => 4.10];

        $this->assertEqualsWithDelta(375.0, $this->subject->calculateTotalCurrencyType($usd, 100.0), 0.0001);
        $this->assertEqualsWithDelta(153.9, $this->subject->calculateTotalCurrencyType((object) ['currency_type_id' => 'USD', 'exchange_rate_sale' => 3.80], 40.50), 0.0001);
        $this->assertSame(100.0, $this->subject->calculateTotalCurrencyType($pen, 100.0));
        $this->assertSame(100.0, $this->subject->calculateTotalCurrencyType($lowercaseUsd, 100.0));
        $this->assertSame(100.0, $this->subject->calculateTotalCurrencyType($nullCurrency, 100.0));
        $this->assertSame(100.0, $this->subject->calculateTotalCurrencyType($eur, 100.0));
    }

    public function test_get_total_credit_notes_sums_only_credit_notes_in_accepted_states_with_usd_conversion(): void
    {
        $document = $this->makeAssociatedRecord(1, 'PEN', 1.0, [
            $this->makeAffectedNote('credit', '01', 'PEN', 10.0),          // included: 10.00
            $this->makeAffectedNote('credit', '03', 'PEN', 15.0),          // included: 15.00
            $this->makeAffectedNote('credit', '05', 'USD', 20.0, 3.80),    // included: 76.00
            $this->makeAffectedNote('credit', '07', 'PEN', 5.0),           // included: 5.00
            $this->makeAffectedNote('credit', '13', 'PEN', 4.5),           // included: 4.50
            $this->makeAffectedNote('credit', '09', 'PEN', 100.0),         // rejected: excluded
            $this->makeAffectedNote('credit', '11', 'USD', 50.0, 3.80),    // voided: excluded
            $this->makeAffectedNote('debit', '05', 'PEN', 200.0),          // debit note: excluded
        ]);

        // 10 + 15 + 76 + 5 + 4.5 = 110.50
        $this->assertEqualsWithDelta(110.5, $this->subject->getTotalCreditNotes($document), 0.0001);
    }

    public function test_get_sum_payment_handles_support_and_eloquent_collections_without_error(): void
    {
        $penDocument = $this->makeAssociatedRecord(77, 'PEN', 1.0);
        $row = $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', 45.5, $penDocument);

        $this->assertEqualsWithDelta(0.0, $this->subject->getSumPayment(new Collection(), DocumentPayment::class), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->subject->getSumPayment(new EloquentCollection([new GlobalPayment()]), DocumentPayment::class), 0.0001);
        $this->assertEqualsWithDelta(45.5, $this->subject->getSumPayment(new Collection([$row]), DocumentPayment::class), 0.0001);
    }

    public function test_get_sum_payment_deduplicates_credit_notes_per_document_and_skips_orphans(): void
    {
        // Document #10 (PEN) has TWO payments (60 + 40 = 100) and credit notes:
        // accepted '05' (25 PEN), voided '11' (50 PEN), rejected '09' (15 PEN).
        // Credit note deduction (25) must be subtracted ONCE per document, not once per payment.
        $penDocument = $this->makeAssociatedRecord(10, 'PEN', 1.0, [
            $this->makeAffectedNote('credit', '05', 'PEN', 25.0),
            $this->makeAffectedNote('credit', '11', 'PEN', 50.0),
            $this->makeAffectedNote('credit', '09', 'PEN', 15.0),
        ]);

        // Document #11 (USD, rate 3.80) has one payment (50 USD = 190 PEN)
        // and one accepted USD credit note (10 USD * 3.80 = 38 PEN).
        $usdDocument = $this->makeAssociatedRecord(11, 'USD', 3.80, [
            $this->makeAffectedNote('credit', '05', 'USD', 10.0, 3.80),
        ]);

        $penSaleNote = $this->makeAssociatedRecord(20, 'PEN', 1.0);
        $usdSaleNote = $this->makeAssociatedRecord(21, 'USD', 3.70);
        $penPurchase = $this->makeAssociatedRecord(30, 'PEN', 1.0);
        $usdPurchase = $this->makeAssociatedRecord(31, 'USD', 3.75);
        $penExpense = $this->makeAssociatedRecord(40, 'PEN', 1.0);
        $usdExpense = $this->makeAssociatedRecord(41, 'USD', 3.80);
        $penIncome = $this->makeAssociatedRecord(50, 'PEN', 1.0);
        $usdIncome = $this->makeAssociatedRecord(51, 'USD', 3.70);

        $records = $this->makeHybridDestination([
            // Document payments
            $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', 60.0, $penDocument),
            $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', 40.0, $penDocument),
            $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', 50.0, $usdDocument),
            // Orphan rows (null payment / null associated record) must be ignored
            $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', null, null),
            $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', 999.0, null),
            // Sale note payments
            $this->makeGlobalPaymentRow(SaleNotePayment::class, 'sale_note', 80.0, $penSaleNote),
            $this->makeGlobalPaymentRow(SaleNotePayment::class, 'sale_note', 20.0, $usdSaleNote),
            // Purchase payments
            $this->makeGlobalPaymentRow(PurchasePayment::class, 'purchase', 120.0, $penPurchase),
            $this->makeGlobalPaymentRow(PurchasePayment::class, 'purchase', 10.0, $usdPurchase),
            // Expense payments
            $this->makeGlobalPaymentRow(ExpensePayment::class, 'expense', 35.5, $penExpense),
            $this->makeGlobalPaymentRow(ExpensePayment::class, 'expense', 10.0, $usdExpense),
            // Income payments
            $this->makeGlobalPaymentRow(IncomePayment::class, 'income', 55.0, $penIncome),
            $this->makeGlobalPaymentRow(IncomePayment::class, 'income', 15.0, $usdIncome),
        ]);

        // Documents: (60 + 40 - 25) + (50 * 3.80 - 10 * 3.80) = 75 + 152 = 227.0
        $this->assertEqualsWithDelta(227.0, $this->subject->getSumPayment($records, DocumentPayment::class), 0.0001);
        // Sale notes: 80 + (20 * 3.70) = 154.0
        $this->assertEqualsWithDelta(154.0, $this->subject->getSumPayment($records, SaleNotePayment::class), 0.0001);
        // Purchases: 120 + (10 * 3.75) = 157.5
        $this->assertEqualsWithDelta(157.5, $this->subject->getSumPayment($records, PurchasePayment::class), 0.0001);
        // Expenses: 35.5 + (10 * 3.80) = 73.5
        $this->assertEqualsWithDelta(73.5, $this->subject->getSumPayment($records, ExpensePayment::class), 0.0001);
        // Incomes: 55 + (15 * 3.70) = 110.5
        $this->assertEqualsWithDelta(110.5, $this->subject->getSumPayment($records, IncomePayment::class), 0.0001);
    }

    public function test_get_balance_by_cash_handles_eloquent_collection(): void
    {
        $result = $this->subject->getBalanceByCash(new EloquentCollection());

        $this->assertSame('cash', $result['id']);
        $this->assertSame('0.00', $result['balance']);
    }

    public function test_get_balance_by_cash_combines_income_and_cash_transactions_and_formats_keys(): void
    {
        $doc = $this->makeAssociatedRecord(1, 'PEN', 1.0, [
            $this->makeAffectedNote('credit', '05', 'PEN', 10.0),
        ]);
        $usdSaleNote = $this->makeAssociatedRecord(2, 'USD', 3.80);
        $quotation = $this->makeAssociatedRecord(3, 'PEN', 1.0);
        $contract = $this->makeAssociatedRecord(4, 'PEN', 1.0);
        $income = $this->makeAssociatedRecord(5, 'PEN', 1.0);
        $techService = $this->makeAssociatedRecord(6, 'PEN', 1.0);
        $purchase = $this->makeAssociatedRecord(7, 'USD', 3.75);
        $expense = $this->makeAssociatedRecord(8, 'PEN', 1.0);
        // CashTransaction is summed directly via $row->payment->payment with NO currency conversion.
        $cashPosAssociatedUsd = $this->makeAssociatedRecord(9, 'USD', 3.90);

        $cashRecords = $this->makeHybridDestination([
            $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', 100.0, $doc),                // 100 - 10 = 90.00
            $this->makeGlobalPaymentRow(SaleNotePayment::class, 'sale_note', 20.0, $usdSaleNote),        // 76.00
            $this->makeGlobalPaymentRow(QuotationPayment::class, 'quotation', 30.25, $quotation),        // 30.25
            $this->makeGlobalPaymentRow(ContractPayment::class, 'contract', 40.50, $contract),           // 40.50
            $this->makeGlobalPaymentRow(IncomePayment::class, 'income', 50.0, $income),                  // 50.00
            $this->makeGlobalPaymentRow(CashTransaction::class, 'cash_transaction', 150.0, $cashPosAssociatedUsd), // raw 150.00 (no FX)
            $this->makeGlobalPaymentRow(TechnicalServicePayment::class, 'technical_service', 25.0, $techService),  // 25.00
            $this->makeGlobalPaymentRow(PurchasePayment::class, 'purchase', 10.0, $purchase),            // 37.50
            $this->makeGlobalPaymentRow(ExpensePayment::class, 'expense', 42.25, $expense),              // 42.25
        ]);

        $result = $this->subject->getBalanceByCash($cashRecords);

        // Entry = 90 + 76 + 30.25 + 40.50 + 50 + 150 + 25 = 461.75
        // Egress = 37.50 + 42.25 = 79.75
        // Balance = 461.75 - 79.75 = 382.00
        // income_payment = 50.00 (IncomePayment) + 150.00 (CashTransaction) = 200.00
        $this->assertSame([
            'id' => 'cash',
            'description' => 'CAJA GENERAL',
            'expense_payment' => '42.25',
            'sale_note_payment' => '76.00',
            'quotation_payment' => '30.25',
            'contract_payment' => '40.50',
            'income_payment' => '200.00',
            'document_payment' => '90.00',
            'purchase_payment' => '37.50',
            'technical_service_payment' => '25.00',
            'balance' => '382.00',
        ], $result);
    }

    public function test_get_balance_by_bank_accounts_freezes_pen_and_usd_currency_mismatch_quirk(): void
    {
        $penDoc = $this->makeAssociatedRecord(101, 'PEN', 1.0);
        $usdDoc = $this->makeAssociatedRecord(102, 'USD', 3.75);
        $penExpense = $this->makeAssociatedRecord(103, 'PEN', 1.0);
        $usdPurchase = $this->makeAssociatedRecord(104, 'USD', 3.75);

        $penAccount = (object) [
            'id' => 1,
            'currency_type_id' => 'PEN',
            'description' => 'Cuenta Corriente Soles',
            'initial_balance' => 1000.00,
            'bank' => (object) ['description' => 'BCP'],
            'global_destination' => $this->makeHybridDestination([
                $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', 250.50, $penDoc),
                $this->makeGlobalPaymentRow(ExpensePayment::class, 'expense', 50.25, $penExpense),
                // CashTransaction rows are NOT included in getBalanceByBankAcounts.
                $this->makeGlobalPaymentRow(CashTransaction::class, 'cash_transaction', 999.00, $penDoc),
            ]),
        ];

        // USD bank account quirk characterization:
        // initial_balance (500.00 USD) is added RAW without FX conversion, whereas
        // USD document (100 * 3.75 = 375.00) and USD purchase (20 * 3.75 = 75.00)
        // are converted to PEN via calculateTotalCurrencyType(), mixing currencies.
        $usdAccount = (object) [
            'id' => 2,
            'currency_type_id' => 'USD',
            'description' => 'Cuenta Ahorros Dolares',
            'initial_balance' => 500.00,
            'bank' => (object) ['description' => 'BBVA'],
            'global_destination' => $this->makeHybridDestination([
                $this->makeGlobalPaymentRow(DocumentPayment::class, 'document', 100.00, $usdDoc),
                $this->makeGlobalPaymentRow(PurchasePayment::class, 'purchase', 20.00, $usdPurchase),
            ]),
        ];

        $balances = $this->subject->getBalanceByBankAcounts(collect([$penAccount, $usdAccount]))->values()->all();

        $this->assertSame([
            [
                'id' => 1,
                'description' => 'BCP - PEN - Cuenta Corriente Soles',
                'expense_payment' => '50.25',
                'sale_note_payment' => '0.00',
                'quotation_payment' => '0.00',
                'contract_payment' => '0.00',
                'document_payment' => '250.50',
                'purchase_payment' => '0.00',
                'income_payment' => '0.00',
                'technical_service_payment' => '0.00',
                'balance' => '1200.25',
            ],
            [
                'id' => 2,
                'description' => 'BBVA - USD - Cuenta Ahorros Dolares',
                'expense_payment' => '0.00',
                'sale_note_payment' => '0.00',
                'quotation_payment' => '0.00',
                'contract_payment' => '0.00',
                'document_payment' => '375.00',
                'purchase_payment' => '75.00',
                'income_payment' => '0.00',
                'technical_service_payment' => '0.00',
                'balance' => '800.00',
            ],
        ], $balances);
    }

    public function test_get_balance_by_bank_accounts_handles_eloquent_collection_relation(): void
    {
        $account = (object) [
            'id' => 3,
            'currency_type_id' => 'PEN',
            'description' => 'Test',
            'initial_balance' => 150.0,
            'bank' => (object) ['description' => 'BCP'],
            'global_destination' => new EloquentCollection(),
        ];

        $balances = $this->subject->getBalanceByBankAcounts(collect([$account]))->values()->all();

        $this->assertCount(1, $balances);
        $this->assertSame('150.00', $balances[0]['balance']);
    }
}
