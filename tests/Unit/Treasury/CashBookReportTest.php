<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Models\IncomePayment;
use Modules\Pos\Models\CashTransaction;
use Modules\Treasury\Models\PaymentEvidence;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\CashBookReport;
use PHPUnit\Framework\TestCase;

class CashBookReportTest extends TestCase
{
    use Phase4Harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();

        // Add company name/number, payment_method_types, singular `income`, `income_payments`, `cash_transactions`, and payment_method_type_id
        $this->db->statement('alter table companies add column number text null');
        $this->db->statement('alter table companies add column name text null');
        $this->db->table('companies')->where('id', 1)->update(['number' => '20555555551', 'name' => 'Empresa Demo SAC']);

        $this->db->statement('create table payment_method_types (id text primary key, description text, sunat_code text null, is_cash integer default 0)');
        $this->db->table('payment_method_types')->insert([
            ['id' => '01', 'description' => 'Efectivo', 'sunat_code' => '008', 'is_cash' => 1],
            ['id' => '04', 'description' => 'Transferencia', 'sunat_code' => '003', 'is_cash' => 0],
            ['id' => '02', 'description' => 'Tarjeta crédito', 'sunat_code' => '006', 'is_cash' => 0],
        ]);
        $this->db->statement('alter table document_payments add column payment_method_type_id text null');
        $this->db->statement('alter table purchase_payments add column payment_method_type_id text null');

        $this->db->statement('create table income (id integer primary key autoincrement, user_id integer, establishment_id integer, state_type_id text, customer text null, number text null, date_of_issue text, currency_type_id text, exchange_rate_sale numeric, total numeric)');
        $this->db->statement('create table income_payments (id integer primary key autoincrement, income_id integer, date_of_payment text, payment numeric, reference text null, payment_method_type_id text null)');
        $this->db->statement('create table cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text, description text null, payment numeric)');
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function report(): CashBookReport
    {
        return new CashBookReport($this->makeBalanceService());
    }

    private function adminScope(): array
    {
        return ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];
    }

    public function test_format_11_builds_official_cash_rows_and_reconciles_credit_notes(): void
    {
        $cashId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1,
            'date_opening' => '2026-10-01',
            'time_opening' => '08:00:00',
            'beginning_balance' => 300.00,
            'final_balance' => 0,
            'income' => 0,
            'state' => 1,
            'reference_number' => 'C-01',
        ]);

        // Opening cash transaction before November
        $ctId = (int) $this->db->table('cash_transactions')->insertGetId([
            'cash_id' => $cashId,
            'date' => '2026-10-20',
            'description' => 'Apertura de caja',
            'payment' => 300.00,
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => $cashId,
            'destination_type' => Cash::class,
            'payment_id' => $ctId,
            'payment_type' => CashTransaction::class,
        ]);

        // Document paid in cash in November + Credit Note issued in November for 100 PEN
        $docId = $this->insertDocument(['series' => 'F001', 'number' => '00010', 'date_of_issue' => '2026-11-05', 'total' => 500.00]);
        $dpId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $docId,
            'payment' => 500.00,
            'date_of_payment' => '2026-11-05',
            'payment_method_type_id' => '01',
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => $cashId,
            'destination_type' => Cash::class,
            'payment_id' => $dpId,
            'payment_type' => DocumentPayment::class,
        ]);

        $cnDocId = $this->insertDocument([
            'document_type_id' => '07',
            'series' => 'FC01',
            'number' => '00001',
            'date_of_issue' => '2026-11-12',
            'total' => 100.00,
        ]);
        $this->db->table('notes')->insert([
            'document_id' => $cnDocId,
            'note_type' => 'credit',
            'affected_document_id' => $docId,
        ]);

        // Singular `income` table payment in cash
        $incId = (int) $this->db->table('income')->insertGetId([
            'user_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '01',
            'customer' => 'Varios',
            'number' => 'ING-09',
            'date_of_issue' => '2026-11-08',
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 1,
            'total' => 80.00,
        ]);
        $ipId = (int) $this->db->table('income_payments')->insertGetId([
            'income_id' => $incId,
            'date_of_payment' => '2026-11-08',
            'payment' => 80.00,
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => $cashId,
            'destination_type' => Cash::class,
            'payment_id' => $ipId,
            'payment_type' => IncomePayment::class,
        ]);

        // Active TreasuryMovement in cash with PCGE concept
        $this->db->table('treasury_concepts')->where('id', 5)->update(['pcge_account' => '6591']);
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-10',
            'kind' => 'expense',
            'direction' => -1,
            'destination_type' => Cash::class,
            'destination_id' => $cashId,
            'currency_type_id' => 'PEN',
            'amount' => 45.00,
            'amount_pen' => 45.00,
            'treasury_concept_id' => 5,
            'description' => 'Movilidad local',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);

        $res = $this->report()->buildFormat11([
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
            'cash_id' => $cashId,
        ], $this->adminScope());

        $this->assertSame('1.1', $res['meta']['format']);
        $this->assertSame('20555555551', $res['meta']['company_ruc']);
        $this->assertSame('Empresa Demo SAC', $res['meta']['company_name']);
        $this->assertSame(300.0, $res['opening_balance']);
        $this->assertCount(3, $res['rows']);
        $this->assertSame('000001', $res['rows'][0]['correlative']);
        $this->assertSame('000002', $res['rows'][1]['correlative']);
        $this->assertSame('000003', $res['rows'][2]['correlative']);
        $this->assertSame('6591', $res['rows'][2]['pcge_code']);

        // Book totals: opening 300 + debit 580 - credit 45 = 835
        $this->assertSame(580.0, $res['totals']['debit']);
        $this->assertSame(45.0, $res['totals']['credit']);
        $this->assertSame(835.0, $res['totals']['closing_balance']);
        // Credit note adjustment = -100, System closing balance = 735, Reconciliation diff = 0.0!
        $this->assertCount(1, $res['credit_note_adjustments']);
        $this->assertSame(-100.0, $res['totals']['credit_note_adjustments']);
        $this->assertSame(735.0, $res['totals']['system_closing_balance']);
        $this->assertSame(0.0, $res['totals']['reconciliation_difference']);
    }

    public function test_format_12_populates_all_10_sunat_columns_with_evidence_priority_and_reconciles_credit_notes(): void
    {
        // Document payment with PaymentEvidence overriding sunat_payment_code, operation_number, and payer name
        $docId = $this->insertDocument(['series' => 'F001', 'number' => '00200', 'date_of_issue' => '2026-11-03', 'total' => 1500.00]);
        $dpId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $docId,
            'payment' => 1500.00,
            'date_of_payment' => '2026-11-04',
            'reference' => 'REF-LEGACY',
            'payment_method_type_id' => '04',
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => 1,
            'destination_type' => BankAccount::class,
            'payment_id' => $dpId,
            'payment_type' => DocumentPayment::class,
        ]);
        PaymentEvidence::create([
            'payable_type' => DocumentPayment::class,
            'payable_id' => $dpId,
            'sunat_payment_code' => '001',
            'operation_number' => 'OP-EV-88811',
            'operation_date' => '2026-11-04',
            'payer_or_beneficiary_name' => 'Pagador Evidencia SAC',
        ]);

        // Purchase payment without evidence: falls back to PaymentMethodType.sunat_code, supplier name, payment.reference
        $purId = $this->insertPurchase(['series' => 'F900', 'number' => '00055', 'date_of_issue' => '2026-11-06', 'total' => 600.00]);
        $ppId = (int) $this->db->table('purchase_payments')->insertGetId([
            'purchase_id' => $purId,
            'payment' => 600.00,
            'date_of_payment' => '2026-11-07',
            'reference' => 'BCP-TX-777',
            'payment_method_type_id' => '02',
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => 1,
            'destination_type' => BankAccount::class,
            'payment_id' => $ppId,
            'payment_type' => PurchasePayment::class,
        ]);

        // TreasuryMovement with sunat_payment_code, operation_number, counterparty_name
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-10',
            'kind' => 'bank_fee',
            'direction' => -1,
            'destination_type' => BankAccount::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 15.00,
            'amount_pen' => 15.00,
            'sunat_payment_code' => '003',
            'operation_number' => 'FEE-202611',
            'counterparty_name' => 'BCP',
            'treasury_concept_id' => 2,
            'description' => 'Mantenimiento de cuenta',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);

        // Credit note on $docId for 200 PEN
        $cnDocId = $this->insertDocument([
            'document_type_id' => '07',
            'series' => 'FC01',
            'number' => '00002',
            'date_of_issue' => '2026-11-15',
            'total' => 200.00,
        ]);
        $this->db->table('notes')->insert([
            'document_id' => $cnDocId,
            'note_type' => 'credit',
            'affected_document_id' => $docId,
        ]);

        $res = $this->report()->buildFormat12([
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
            'bank_account_id' => 1,
        ], $this->adminScope());

        $this->assertSame('1.2', $res['meta']['format']);
        $this->assertSame('BCP', $res['meta']['bank_name']);
        $this->assertSame('191-001', $res['meta']['account_number']);
        $this->assertSame('PEN', $res['meta']['currency_type_id']);
        $this->assertSame(5000.0, $res['opening_balance']);
        $this->assertCount(3, $res['rows']);

        // Row 1: PaymentEvidence wins for code, counterparty, transaction number
        $r1 = $res['rows'][0];
        $this->assertSame('000001', $r1['correlative']);
        $this->assertSame('001', $r1['sunat_payment_code']);
        $this->assertSame('Pagador Evidencia SAC', $r1['counterparty']);
        $this->assertSame('OP-EV-88811', $r1['transaction_number']);
        $this->assertSame('1212', $r1['pcge_code']);
        $this->assertSame(1500.0, $r1['debit']);
        $this->assertSame(0.0, $r1['credit']);
        $this->assertSame(6500.0, $r1['running_balance']);

        // Row 2: Purchase payment fallback to PaymentMethodType(02 -> 006), Person(10), reference
        $r2 = $res['rows'][1];
        $this->assertSame('006', $r2['sunat_payment_code']);
        $this->assertSame('Proveedor Uno SA', $r2['counterparty']);
        $this->assertSame('BCP-TX-777', $r2['transaction_number']);
        $this->assertSame('4212', $r2['pcge_code']);
        $this->assertSame(600.0, $r2['credit']);

        // Row 3: TreasuryMovement
        $r3 = $res['rows'][2];
        $this->assertSame('003', $r3['sunat_payment_code']);
        $this->assertSame('BCP', $r3['counterparty']);
        $this->assertSame('FEE-202611', $r3['transaction_number']);
        $this->assertSame('639', $r3['pcge_code']);
        $this->assertSame(15.0, $r3['credit']);

        // Book closing = 5000 + 1500 - 615 = 5885; CN = -200; System closing = 5685; Diff = 0.0
        $this->assertSame(5885.0, $res['totals']['closing_balance']);
        $this->assertSame(-200.0, $res['totals']['credit_note_adjustments']);
        $this->assertSame(5685.0, $res['totals']['system_closing_balance']);
        $this->assertSame(0.0, $res['totals']['reconciliation_difference']);
    }

    public function test_format_12_enforces_account_establishment_authorization_and_validates_required_account(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 7, 'bank_id' => 1, 'description' => 'Sucursal', 'number' => '191-700',
            'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 100.00,
            'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2,
        ]);

        try {
            $this->report()->buildFormat12([
                'date_start' => '2026-11-01',
                'date_end' => '2026-11-30',
                'bank_account_id' => 7,
            ], ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1]);
            $this->fail('Expected AuthorizationException on foreign bank account.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otra sede', $e->getMessage());
        }

        $this->expectException(ValidationException::class);
        $this->report()->buildFormat12([
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
        ], $this->adminScope());
    }

    public function test_format_11_isolates_non_admin_to_own_cash_boxes_and_denies_other_cashier_in_same_establishment(): void
    {
        // Admin (user 1) and Seller (user 2) share establishment 1
        $adminCashId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1, 'date_opening' => '2026-10-01', 'time_opening' => '08:00:00',
            'beginning_balance' => 400.00, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'ADM',
        ]);
        $sellerCashId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 2, 'date_opening' => '2026-10-01', 'time_opening' => '08:00:00',
            'beginning_balance' => 150.00, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'SEL',
        ]);

        $ctAdmin = (int) $this->db->table('cash_transactions')->insertGetId([
            'cash_id' => $adminCashId, 'date' => '2026-11-05', 'description' => 'Admin sale', 'payment' => 400.00,
        ]);
        $ctSeller = (int) $this->db->table('cash_transactions')->insertGetId([
            'cash_id' => $sellerCashId, 'date' => '2026-11-06', 'description' => 'Seller sale', 'payment' => 150.00,
        ]);
        $this->db->table('global_payments')->insert([
            ['destination_id' => $adminCashId, 'destination_type' => Cash::class, 'payment_id' => $ctAdmin, 'payment_type' => CashTransaction::class],
            ['destination_id' => $sellerCashId, 'destination_type' => Cash::class, 'payment_id' => $ctSeller, 'payment_type' => CashTransaction::class],
        ]);

        $sellerScope = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1];

        // Omitting cash_id returns ONLY seller's own cash movements
        $ownOnly = $this->report()->buildFormat11([
            'date_start' => '2026-11-01', 'date_end' => '2026-11-30',
        ], $sellerScope);
        $this->assertCount(1, $ownOnly['rows']);
        $this->assertSame(150.0, $ownOnly['totals']['debit']);
        $this->assertSame(150.0, $ownOnly['totals']['system_closing_balance']);

        // Explicitly passing admin's cash_id in same establishment MUST throw AuthorizationException
        $this->expectException(AuthorizationException::class);
        $this->report()->buildFormat11([
            'date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'cash_id' => $adminCashId,
        ], $sellerScope);
    }

    public function test_format_12_usd_bank_account_native_currency_and_cross_currency_payment_parity(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 8, 'bank_id' => 1, 'description' => 'BCP USD', 'number' => '191-USD-8',
            'currency_type_id' => 'USD', 'status' => 1, 'initial_balance' => 1000.00,
            'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1,
        ]);

        // USD document payment (200 USD) into USD account
        $usdDocId = $this->insertDocument([
            'series' => 'F002', 'number' => '00001', 'date_of_issue' => '2026-11-05',
            'total' => 200.00, 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.75,
        ]);
        $dpUsdId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $usdDocId, 'payment' => 200.00, 'date_of_payment' => '2026-11-06', 'payment_method_type_id' => '04',
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => 8, 'destination_type' => BankAccount::class, 'payment_id' => $dpUsdId, 'payment_type' => DocumentPayment::class,
        ]);

        // PEN document payment (375 PEN at TC 3.75 => 100 USD) into USD account
        $penDocId = $this->insertDocument([
            'series' => 'F001', 'number' => '00099', 'date_of_issue' => '2026-11-07',
            'total' => 375.00, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 3.75,
        ]);
        $dpPenId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $penDocId, 'payment' => 375.00, 'date_of_payment' => '2026-11-08', 'payment_method_type_id' => '04',
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => 8, 'destination_type' => BankAccount::class, 'payment_id' => $dpPenId, 'payment_type' => DocumentPayment::class,
        ]);

        // USD TreasuryMovement outflow (50 USD, amount_pen 187.50)
        TreasuryMovement::create([
            'establishment_id' => 1, 'date' => '2026-11-12', 'kind' => 'bank_fee', 'direction' => -1,
            'destination_type' => BankAccount::class, 'destination_id' => 8, 'currency_type_id' => 'USD',
            'amount' => 50.00, 'amount_pen' => 187.50, 'description' => 'Wire fee', 'state' => 'active', 'user_id' => 1,
        ]);

        $res = $this->report()->buildFormat12([
            'date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'bank_account_id' => 8,
        ], $this->adminScope());

        $this->assertSame('USD', $res['meta']['currency_type_id']);
        $this->assertSame(1000.0, $res['opening_balance']);
        $this->assertSame(300.0, $res['totals']['debit']); // 200 USD + (375 / 3.75 = 100 USD)
        $this->assertSame(50.0, $res['totals']['credit']);
        $this->assertSame(1250.0, $res['totals']['closing_balance']);
        $this->assertSame(1250.0, $res['totals']['system_closing_balance']);
        $this->assertSame(0.0, $res['totals']['reconciliation_difference']);
    }

    public function test_service_rejects_a_date_range_wider_than_the_configured_maximum(): void
    {
        $this->assertSame(366, CashBookReport::maxRangeDays());

        try {
            $this->report()->buildFormat11(['date_start' => '2025-01-01', 'date_end' => '2026-11-30'], $this->adminScope());
            $this->fail('Expected ValidationException for a range wider than 366 days.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date_end', $e->errors());
        }

        // A configurable limit is honoured by the service.
        $this->app['config']->set('treasury.reports.max_range_days', 10);
        $this->expectException(ValidationException::class);
        $this->report()->buildFormat11(['date_start' => '2026-11-01', 'date_end' => '2026-11-30'], $this->adminScope());
    }

    private function bankDocumentPayment(int $documentId, float $amount, string $date, ?string $methodType, int $accountId = 1): void
    {
        $paymentId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $documentId, 'payment' => $amount, 'date_of_payment' => $date, 'payment_method_type_id' => $methodType,
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => $accountId, 'destination_type' => BankAccount::class,
            'payment_id' => $paymentId, 'payment_type' => DocumentPayment::class,
        ]);
    }

    public function test_format_12_does_not_invent_a_sunat_payment_code_and_flags_the_row(): void
    {
        $withCode = $this->insertDocument(['date_of_issue' => '2026-11-05', 'total' => 100.00]);
        $withoutCode = $this->insertDocument(['date_of_issue' => '2026-11-06', 'total' => 200.00]);
        $this->bankDocumentPayment($withCode, 100.00, '2026-11-05', '04');
        $this->bankDocumentPayment($withoutCode, 200.00, '2026-11-06', null);

        $report = $this->report()->buildFormat12(
            ['date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'bank_account_id' => 1],
            $this->adminScope()
        );

        $this->assertCount(2, $report['rows']);
        $this->assertSame('003', $report['rows'][0]['sunat_payment_code']);
        $this->assertFalse($report['rows'][0]['missing_payment_code']);
        $this->assertNull($report['rows'][1]['sunat_payment_code']);
        $this->assertTrue($report['rows'][1]['missing_payment_code']);
        $this->assertSame('Sin código', $report['rows'][1]['sunat_payment_label']);
        $this->assertSame(1, $report['totals']['missing_payment_code_count']);
    }

    public function test_format_12_hides_other_establishment_payments_on_a_shared_account_from_non_admins(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 7, 'bank_id' => 1, 'description' => 'Compartida', 'number' => '191-007', 'currency_type_id' => 'PEN',
            'status' => 1, 'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => null,
        ]);
        $mine = $this->insertDocument(['establishment_id' => 1, 'series' => 'F001', 'number' => '11', 'date_of_issue' => '2026-11-05', 'total' => 100.00]);
        $other = $this->insertDocument(['establishment_id' => 2, 'series' => 'F002', 'number' => '22', 'date_of_issue' => '2026-11-06', 'total' => 300.00]);
        $this->bankDocumentPayment($mine, 100.00, '2026-11-05', '04', 7);
        $this->bankDocumentPayment($other, 300.00, '2026-11-06', '04', 7);

        $filters = ['date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'bank_account_id' => 7];

        $seller = $this->report()->buildFormat12($filters, ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1]);
        $this->assertCount(1, $seller['rows']);
        $this->assertStringContainsString('F001-11', $seller['rows'][0]['description']);
        $this->assertEquals(300.00, $seller['totals']['out_of_scope_net']);
        $this->assertEquals(0.0, $seller['totals']['reconciliation_difference']);

        $admin = $this->report()->buildFormat12($filters, $this->adminScope());
        $this->assertCount(2, $admin['rows']);
        $this->assertEquals(0.0, $admin['totals']['out_of_scope_net']);
        $this->assertEquals(0.0, $admin['totals']['reconciliation_difference']);
    }

    public function test_pure_rounding_drift_on_converted_rows_is_reported_apart_and_not_as_a_reconciliation_difference(): void
    {
        $cashId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1, 'date_opening' => '2026-11-01', 'time_opening' => '08:00:00', 'beginning_balance' => 0,
            'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'C-USD',
        ]);

        // USD invoice collected in three installments at 3.7567: rows round to 37.60 each (112.80) while the
        // balance rounds the document aggregate (30.03 x 3.7567 = 112.81).
        $docId = $this->insertDocument(['date_of_issue' => '2026-11-03', 'total' => 30.03, 'currency_type_id' => 'USD', 'exchange_rate_sale' => 3.7567]);
        foreach (['2026-11-03', '2026-11-04', '2026-11-05'] as $date) {
            $paymentId = (int) $this->db->table('document_payments')->insertGetId([
                'document_id' => $docId, 'payment' => 10.01, 'date_of_payment' => $date, 'payment_method_type_id' => '01',
            ]);
            $this->db->table('global_payments')->insert([
                'destination_id' => $cashId, 'destination_type' => Cash::class,
                'payment_id' => $paymentId, 'payment_type' => DocumentPayment::class,
            ]);
        }

        $report = $this->report()->buildFormat11(['date_start' => '2026-11-01', 'date_end' => '2026-11-30'], $this->adminScope());

        $this->assertEquals(112.80, $report['totals']['closing_balance']);
        $this->assertEquals(112.81, $report['totals']['system_closing_balance']);
        $this->assertSame(3, $report['totals']['converted_rows']);
        $this->assertEquals(0.01, $report['totals']['rounding_drift']);
        $this->assertEquals(0.0, $report['totals']['reconciliation_difference']);
    }
}
