<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\Purchase;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Validation\ValidationException;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Pos\Models\CashTransaction;
use Modules\Treasury\Models\DetractionRecord;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\CashFlowService;
use PHPUnit\Framework\TestCase;

class CashFlowServiceTest extends TestCase
{
    use Phase4Harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();

        // Add singular `income`, `income_payments`, and `cash_transactions` matching real MariaDB schema
        $this->db->statement('create table income (id integer primary key autoincrement, user_id integer, establishment_id integer, state_type_id text, customer text null, number text null, date_of_issue text, currency_type_id text, exchange_rate_sale numeric, total numeric)');
        $this->db->statement('create table income_payments (id integer primary key autoincrement, income_id integer, date_of_payment text, payment numeric, reference text null)');
        $this->db->statement('create table cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text, description text null, payment numeric)');
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function service(): CashFlowService
    {
        return new CashFlowService($this->makeBalanceService(), $this->makeReceivablePayableService());
    }

    private function adminScope(): array
    {
        return ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];
    }

    public function test_real_cash_flow_groups_by_concept_and_separates_internal_transfers_when_consolidated(): void
    {
        // Cash box owned by user 1 (establishment 1)
        $cashId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1,
            'date_opening' => '2026-10-01',
            'time_opening' => '08:00:00',
            'beginning_balance' => 200.00,
            'final_balance' => 0,
            'income' => 0,
            'state' => 1,
            'reference_number' => 'CAJA-01',
        ]);

        // Opening cash transaction before November (contributes to opening_balance)
        $ctId = (int) $this->db->table('cash_transactions')->insertGetId([
            'cash_id' => $cashId,
            'date' => '2026-10-25',
            'description' => 'Saldo inicial POS',
            'payment' => 200.00,
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => $cashId,
            'destination_type' => Cash::class,
            'payment_id' => $ctId,
            'payment_type' => CashTransaction::class,
        ]);

        // November invoice payment into BankAccount 1 (PEN)
        $docId = $this->insertDocument(['date_of_issue' => '2026-11-05', 'total' => 1000.00]);
        $dpId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $docId,
            'payment' => 1000.00,
            'date_of_payment' => '2026-11-05',
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => 1,
            'destination_type' => BankAccount::class,
            'payment_id' => $dpId,
            'payment_type' => DocumentPayment::class,
        ]);

        // Singular `income` table payment in November into Cash
        $incId = (int) $this->db->table('income')->insertGetId([
            'user_id' => 1,
            'establishment_id' => 1,
            'state_type_id' => '01',
            'customer' => 'Ingreso varios',
            'number' => 'ING-001',
            'date_of_issue' => '2026-11-10',
            'currency_type_id' => 'PEN',
            'exchange_rate_sale' => 1,
            'total' => 150.00,
        ]);
        $ipId = (int) $this->db->table('income_payments')->insertGetId([
            'income_id' => $incId,
            'date_of_payment' => '2026-11-10',
            'payment' => 150.00,
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => $cashId,
            'destination_type' => Cash::class,
            'payment_id' => $ipId,
            'payment_type' => IncomePayment::class,
        ]);

        // Purchase payment (outflow) in November
        $purId = $this->insertPurchase(['date_of_issue' => '2026-11-08', 'total' => 400.00]);
        $ppId = (int) $this->db->table('purchase_payments')->insertGetId([
            'purchase_id' => $purId,
            'payment' => 400.00,
            'date_of_payment' => '2026-11-08',
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => 1,
            'destination_type' => BankAccount::class,
            'payment_id' => $ppId,
            'payment_type' => PurchasePayment::class,
        ]);

        // Active TreasuryMovement (concept #2: Comisión bancaria) + Voided movement (must be excluded)
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-12',
            'kind' => 'bank_fee',
            'direction' => -1,
            'destination_type' => BankAccount::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 25.00,
            'amount_pen' => 25.00,
            'treasury_concept_id' => 2,
            'description' => 'Comisión mensual',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-13',
            'kind' => 'expense',
            'direction' => -1,
            'destination_type' => BankAccount::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 999.00,
            'amount_pen' => 999.00,
            'treasury_concept_id' => 5,
            'description' => 'Anulado',
            'state' => TreasuryMovement::STATE_VOIDED,
            'user_id' => 1,
        ]);

        // Internal transfer from BankAccount 1 -> Cash (300 PEN)
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-15',
            'kind' => 'transfer_out',
            'direction' => -1,
            'destination_type' => BankAccount::class,
            'destination_id' => 1,
            'currency_type_id' => 'PEN',
            'amount' => 300.00,
            'amount_pen' => 300.00,
            'description' => 'Retiro a caja',
            'transfer_group' => 'grp-1',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-15',
            'kind' => 'transfer_in',
            'direction' => 1,
            'destination_type' => Cash::class,
            'destination_id' => $cashId,
            'currency_type_id' => 'PEN',
            'amount' => 300.00,
            'amount_pen' => 300.00,
            'description' => 'Ingreso desde banco',
            'transfer_group' => 'grp-1',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);

        // 1) Consolidated all accounts: transfers separated (not inflating operating inflows/outflows)
        $report = $this->service()->getRealCashFlow([
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
            'granularity' => 'month',
            'currency_type_id' => 'ALL',
            'destination_type' => 'all',
        ], $this->adminScope());

        // Opening: bank1(5000) + bank2(100) + bank4(0) + cash(200) = 5300
        $this->assertSame(5300.0, $report['opening_balance']);
        // Operating inflows: 1000 (doc) + 150 (income) = 1150 (transfer 300 excluded!)
        $this->assertSame(1150.0, $report['totals']['inflows']);
        // Operating outflows: 400 (purchase) + 25 (bank_fee) = 425 (voided 999 and transfer 300 excluded!)
        $this->assertSame(425.0, $report['totals']['outflows']);
        $this->assertSame(0.0, $report['totals']['transfers_net']);
        $this->assertCount(2, $report['transfer_rows']);
        $this->assertSame(725.0, $report['totals']['net']);
        $this->assertSame(6025.0, $report['totals']['closing_balance']);

        // 2) Cash-only view: transfer_in IS an inflow to cash!
        $cashOnly = $this->service()->getRealCashFlow([
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
            'granularity' => 'week',
            'destination_type' => 'cash',
        ], $this->adminScope());

        $this->assertSame(200.0, $cashOnly['opening_balance']);
        // 150 (income) + 300 (transfer_in) = 450
        $this->assertSame(450.0, $cashOnly['totals']['inflows']);
        $this->assertSame(0.0, $cashOnly['totals']['outflows']);
        $this->assertSame([], $cashOnly['transfer_rows']);
        $this->assertSame(650.0, $cashOnly['totals']['closing_balance']);
        $this->assertGreaterThanOrEqual(5, count($cashOnly['periods']));
    }

    public function test_projected_cash_flow_avoids_detraction_double_counting_includes_overdue_and_flags_negative_periods(): void
    {
        // Reduce bank initial balances to 500 PEN total so outflows push projected balance negative
        $this->db->table('bank_accounts')->where('id', 1)->update(['initial_balance' => 500.00]);
        $this->db->table('bank_accounts')->where('id', 2)->update(['initial_balance' => 0.00]);

        // Overdue receivable (due 2026-11-10, asOf = 2026-11-20): 200 PEN
        $docOverdue = $this->insertDocument(['date_of_issue' => '2026-10-10', 'total' => 200.00]);
        $this->db->table('document_fee')->insert([
            'document_id' => $docOverdue,
            'date' => '2026-11-10',
            'currency_type_id' => 'PEN',
            'amount' => 200.00,
        ]);

        // Upcoming receivable (due 2026-11-25): 300 PEN
        $docFuture = $this->insertDocument(['date_of_issue' => '2026-11-01', 'total' => 300.00]);
        $this->db->table('document_fee')->insert([
            'document_id' => $docFuture,
            'date' => '2026-11-25',
            'currency_type_id' => 'PEN',
            'amount' => 300.00,
        ]);

        // Purchase with 12% detraction: total 2000, detraction 240 (due 2026-12-05), supplier due 2026-11-28
        $purId = $this->insertPurchase([
            'date_of_issue' => '2026-11-15',
            'date_of_due' => '2026-11-28',
            'total' => 2000.00,
            'detraction' => $this->detractionJson(240.00, '037', 12.0),
        ]);
        $this->db->table('detraction_records')->insert([
            'side' => DetractionRecord::SIDE_PAYABLE,
            'source_type' => Purchase::class,
            'source_id' => $purId,
            'establishment_id' => 1,
            'user_id' => 1,
            'code' => '037',
            'rate' => 0.12,
            'currency_type_id' => 'PEN',
            'base_amount' => 2000.00,
            'amount_pen' => 240.00,
            'bn_account_number' => '00-123-456789',
            'registry_date' => '2026-11-15',
            'due_date' => '2026-12-05',
            'status' => DetractionRecord::STATUS_PENDING,
        ]);

        $projected = $this->service()->getProjectedCashFlow([
            'date_start' => '2026-11-20',
            'date_end' => '2026-12-20',
            'granularity' => 'month',
            'include_overdue' => true,
        ], $this->adminScope(), '2026-11-20');

        // Starting liquidity = 500
        $this->assertSame(500.0, $projected['opening_balance']);
        // Leading overdue bucket exists and holds the 200 PEN overdue receivable
        $this->assertSame('overdue', $projected['periods'][0]['key']);
        $this->assertSame(200.0, $projected['period_summary'][0]['inflows']);

        // Total inflows = 200 (overdue) + 300 (Nov) = 500
        $this->assertSame(500.0, $projected['totals']['inflows']);
        // Total outflows MUST be 2000 (1760 supplier + 240 SPOT detraction), NOT 2240!
        $this->assertSame(2000.0, $projected['totals']['outflows']);
        $outflowByCat = array_column($projected['outflow_rows'], 'total', 'category');
        $this->assertSame(1760.0, $outflowByCat['Pagos a proveedores']);
        $this->assertSame(240.0, $outflowByCat['Detracciones por depositar (SPOT)']);

        // Final projected balance = 500 + 500 - 2000 = -1000 (negative projection alert triggered!)
        $this->assertSame(-1000.0, $projected['totals']['closing_balance']);
        $this->assertTrue($projected['has_negative_projection']);
        $this->assertNotEmpty($projected['negative_periods']);

        // Excluding overdue drops the 200 PEN overdue receivable
        $noOverdue = $this->service()->getProjectedCashFlow([
            'date_start' => '2026-11-20',
            'date_end' => '2026-12-20',
            'granularity' => 'month',
            'include_overdue' => false,
        ], $this->adminScope(), '2026-11-20');

        $this->assertNotSame('overdue', $noOverdue['periods'][0]['key']);
        $this->assertSame(300.0, $noOverdue['totals']['inflows']);
    }

    public function test_non_admin_without_establishment_fails_closed_and_establishment_is_clamped(): void
    {
        // Foreign establishment account & movement
        $this->db->table('bank_accounts')->insert([
            'id' => 9, 'bank_id' => 1, 'description' => 'Sucursal', 'number' => '191-999',
            'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 8000.00,
            'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 2,
            'date' => '2026-11-10',
            'kind' => 'income',
            'direction' => 1,
            'destination_type' => BankAccount::class,
            'destination_id' => 9,
            'currency_type_id' => 'PEN',
            'amount' => 500.00,
            'amount_pen' => 500.00,
            'treasury_concept_id' => 4,
            'description' => 'Ingreso sucursal',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);

        // Non-admin with null establishment fails closed (sees 0)
        $denied = $this->service()->getRealCashFlow([
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
        ], ['is_admin' => false, 'user_id' => 3, 'establishment_id' => null]);

        $this->assertSame(0.0, $denied['opening_balance']);
        $this->assertSame(0.0, $denied['totals']['inflows']);
        $this->assertSame(0.0, $denied['totals']['closing_balance']);

        // Non-admin in est 1 trying to pass establishment_id=2 is clamped to est 1
        $clamped = $this->service()->getRealCashFlow([
            'date_start' => '2026-11-01',
            'date_end' => '2026-11-30',
            'establishment_id' => 2,
        ], ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1]);

        $this->assertSame(1, $clamped['establishment_id']);
        $this->assertSame(5100.0, $clamped['opening_balance']); // only est 1 accounts (5000 + 100)
        $this->assertSame(0.0, $clamped['totals']['inflows']);
    }

    public function test_invalid_date_range_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->getRealCashFlow([
            'date_start' => '2026-11-30',
            'date_end' => '2026-11-01',
        ], $this->adminScope());
    }

    public function test_admin_projected_cash_flow_includes_obligations_created_by_other_users(): void
    {
        // Seller (user_id = 2) creates an invoice receivable and a purchase payable
        $sellerDocId = $this->insertDocument([
            'user_id' => 2,
            'date_of_issue' => '2026-11-10',
            'total' => 750.00,
        ]);
        $this->db->table('document_fee')->insert([
            'document_id' => $sellerDocId,
            'date' => '2026-11-28',
            'currency_type_id' => 'PEN',
            'amount' => 750.00,
        ]);
        $this->insertPurchase([
            'user_id' => 2,
            'date_of_issue' => '2026-11-12',
            'date_of_due' => '2026-12-02',
            'total' => 420.00,
        ]);

        // Admin (user_id = 1) MUST see seller's receivable and payable in projected cash flow
        $projected = $this->service()->getProjectedCashFlow([
            'date_start' => '2026-11-20',
            'date_end' => '2026-12-20',
            'granularity' => 'month',
        ], ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1], '2026-11-20');

        $this->assertSame(750.0, $projected['totals']['inflows']);
        $this->assertSame(420.0, $projected['totals']['outflows']);
    }

    public function test_non_admin_cash_isolation_across_real_and_projected_opening_balance_and_payable_detractions(): void
    {
        // Zero out bank accounts so cash balances are clearly distinguishable
        $this->db->table('bank_accounts')->update(['initial_balance' => 0.00]);

        // Admin (user 1) and Seller (user 2) are in the SAME establishment (1), each with own POS cash box
        $adminCashId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1, 'date_opening' => '2026-10-01', 'time_opening' => '08:00:00',
            'beginning_balance' => 1000.00, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'ADM',
        ]);
        $sellerCashId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => 2, 'date_opening' => '2026-10-01', 'time_opening' => '08:00:00',
            'beginning_balance' => 250.00, 'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'SEL',
        ]);

        $ctAdmin = (int) $this->db->table('cash_transactions')->insertGetId([
            'cash_id' => $adminCashId, 'date' => '2026-10-15', 'description' => 'Admin cash', 'payment' => 1000.00,
        ]);
        $ctSeller = (int) $this->db->table('cash_transactions')->insertGetId([
            'cash_id' => $sellerCashId, 'date' => '2026-10-15', 'description' => 'Seller cash', 'payment' => 250.00,
        ]);
        $this->db->table('global_payments')->insert([
            ['destination_id' => $adminCashId, 'destination_type' => Cash::class, 'payment_id' => $ctAdmin, 'payment_type' => CashTransaction::class],
            ['destination_id' => $sellerCashId, 'destination_type' => Cash::class, 'payment_id' => $ctSeller, 'payment_type' => CashTransaction::class],
        ]);

        // Admin's purchase with payable detraction in est 1 (seller must NOT see this detraction!)
        $adminPurId = $this->insertPurchase(['user_id' => 1, 'date_of_issue' => '2026-11-15', 'date_of_due' => '2026-11-28', 'total' => 1000.00, 'detraction' => $this->detractionJson(120.00)]);
        $this->db->table('detraction_records')->insert([
            'side' => DetractionRecord::SIDE_PAYABLE, 'source_type' => Purchase::class, 'source_id' => $adminPurId,
            'establishment_id' => 1, 'user_id' => 1, 'code' => '037', 'rate' => 0.12, 'currency_type_id' => 'PEN',
            'base_amount' => 1000.00, 'amount_pen' => 120.00, 'bn_account_number' => '00-123',
            'registry_date' => '2026-11-15', 'due_date' => '2026-12-04', 'status' => DetractionRecord::STATUS_PENDING,
        ]);

        $sellerScope = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1];

        $real = $this->service()->getRealCashFlow([
            'date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'destination_type' => 'cash',
        ], $sellerScope);
        $this->assertSame(250.0, $real['opening_balance']);

        $projected = $this->service()->getProjectedCashFlow([
            'date_start' => '2026-11-20', 'date_end' => '2026-12-20', 'granularity' => 'month',
        ], $sellerScope, '2026-11-20');
        $this->assertSame(250.0, $projected['opening_balance']);
        $this->assertSame(0.0, $projected['totals']['outflows']);
    }

    public function test_oversized_date_ranges_are_rejected_for_weekly_and_monthly_granularities(): void
    {
        try {
            $this->service()->getRealCashFlow([
                'date_start' => '2020-01-01',
                'date_end' => '2026-11-30',
                'granularity' => 'week',
            ], $this->adminScope());
            $this->fail('Expected ValidationException for >731 day weekly range.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date_end', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $this->service()->getProjectedCashFlow([
            'date_start' => '2026-01-01',
            'date_end' => '2035-01-01',
            'granularity' => 'month',
        ], $this->adminScope(), '2026-01-01');
    }

    public function test_projected_cash_flow_excludes_cre_retentions_and_already_received_detractions(): void
    {
        // Invoice 1: 1000 PEN with 3% CRE retention (30 PEN) and custom fee 970
        $docRetId = $this->insertDocument([
            'date_of_issue' => '2026-11-10',
            'total' => 1000.00,
            'discounts' => json_encode([['discount_type_id' => '62', 'amount' => 30.00]]),
        ]);
        $this->db->table('document_fee')->insert([
            'document_id' => $docRetId, 'date' => '2026-11-28', 'currency_type_id' => 'PEN', 'amount' => 970.00,
        ]);

        // Invoice 2: 1000 PEN with 12% detraction (120 PEN) ALREADY marked RECEIVED in detraction_records
        $docDetReceivedId = $this->insertDocument([
            'date_of_issue' => '2026-11-11',
            'total' => 1000.00,
            'detraction' => $this->detractionJson(120.00, '037', 12.0),
        ]);
        $this->db->table('document_fee')->insert([
            'document_id' => $docDetReceivedId, 'date' => '2026-11-29', 'currency_type_id' => 'PEN', 'amount' => 880.00,
        ]);
        $this->db->table('detraction_records')->insert([
            'side' => DetractionRecord::SIDE_RECEIVABLE, 'source_type' => Document::class, 'source_id' => $docDetReceivedId,
            'establishment_id' => 1, 'user_id' => 1, 'code' => '037', 'rate' => 0.12, 'currency_type_id' => 'PEN',
            'base_amount' => 1000.00, 'amount_pen' => 120.00, 'bn_account_number' => '00-123',
            'registry_date' => '2026-11-11', 'due_date' => '2026-11-15', 'status' => DetractionRecord::STATUS_RECEIVED,
        ]);

        // Invoice 3: 500 PEN with 12% detraction (60 PEN) STILL PENDING in detraction_records
        $docDetPendingId = $this->insertDocument([
            'date_of_issue' => '2026-11-12',
            'total' => 500.00,
            'detraction' => $this->detractionJson(60.00, '037', 12.0),
        ]);
        $this->db->table('document_fee')->insert([
            'document_id' => $docDetPendingId, 'date' => '2026-11-30', 'currency_type_id' => 'PEN', 'amount' => 440.00,
        ]);
        $this->db->table('detraction_records')->insert([
            'side' => DetractionRecord::SIDE_RECEIVABLE, 'source_type' => Document::class, 'source_id' => $docDetPendingId,
            'establishment_id' => 1, 'user_id' => 1, 'code' => '037', 'rate' => 0.12, 'currency_type_id' => 'PEN',
            'base_amount' => 500.00, 'amount_pen' => 60.00, 'bn_account_number' => '00-123',
            'registry_date' => '2026-11-12', 'due_date' => '2026-11-30', 'status' => DetractionRecord::STATUS_PENDING,
        ]);

        $projected = $this->service()->getProjectedCashFlow([
            'date_start' => '2026-11-20',
            'date_end' => '2026-12-10',
            'granularity' => 'month',
        ], $this->adminScope(), '2026-11-20');

        $byCat = array_column($projected['inflow_rows'], 'total', 'category');
        // Client installments = 970 + 880 + 440 = 2290
        $this->assertSame(2290.0, $byCat['Cobranzas de clientes (cuotas)']);
        // BN detractions = ONLY the 60 PEN pending detraction (NOT 30 CRE retention, NOT 120 already received!)
        $this->assertSame(60.0, $byCat['Detracciones por cobrar (BN)']);
        $this->assertSame(2350.0, $projected['totals']['inflows']);
    }

    public function test_real_cash_flow_handles_usd_missing_fx_cross_currency_spread_and_shared_account_invariant(): void
    {
        // Shared bank account (establishment_id = null) with pre-period movements in est 1 AND est 2
        $this->db->table('bank_accounts')->insert([
            'id' => 10, 'bank_id' => 1, 'description' => 'Compartida PEN', 'number' => '191-010',
            'currency_type_id' => 'PEN', 'status' => 1, 'initial_balance' => 100.00,
            'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => null,
        ]);
        // USD bank account + remove exchange rates to test missing_exchange_rate flag
        $this->db->table('bank_accounts')->insert([
            'id' => 11, 'bank_id' => 1, 'description' => 'BCP USD', 'number' => '191-USD',
            'currency_type_id' => 'USD', 'status' => 1, 'initial_balance' => 100.00,
            'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 2, 'date' => '2026-10-15', 'kind' => 'income', 'direction' => 1,
            'destination_type' => BankAccount::class, 'destination_id' => 10, 'currency_type_id' => 'PEN',
            'amount' => 700.00, 'amount_pen' => 700.00, 'description' => 'Est 2 prior', 'state' => 'active', 'user_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 1, 'date' => '2026-10-15', 'kind' => 'income', 'direction' => 1,
            'destination_type' => BankAccount::class, 'destination_id' => 10, 'currency_type_id' => 'PEN',
            'amount' => 200.00, 'amount_pen' => 200.00, 'description' => 'Est 1 prior', 'state' => 'active', 'user_id' => 1,
        ]);
        // Cross-currency transfer spread in November (transfer_out 375 PEN -> transfer_in 100 USD valued at 374 PEN => net -1.00 PEN)
        TreasuryMovement::create([
            'establishment_id' => 1, 'date' => '2026-11-10', 'kind' => 'transfer_out', 'direction' => -1,
            'destination_type' => BankAccount::class, 'destination_id' => 1, 'currency_type_id' => 'PEN',
            'amount' => 375.00, 'amount_pen' => 375.00, 'description' => 'Transfer out PEN', 'transfer_group' => 'fx-1', 'state' => 'active', 'user_id' => 1,
        ]);
        TreasuryMovement::create([
            'establishment_id' => 1, 'date' => '2026-11-10', 'kind' => 'transfer_in', 'direction' => 1,
            'destination_type' => BankAccount::class, 'destination_id' => 11, 'currency_type_id' => 'USD',
            'amount' => 100.00, 'amount_pen' => 374.00, 'description' => 'Transfer in USD', 'transfer_group' => 'fx-1', 'state' => 'active', 'user_id' => 1,
        ]);

        // Filter by est 1: shared account's est 2 prior movement (700) is NOT in opening, only est 1 (200) + initial (100)
        $est1 = $this->service()->getRealCashFlow([
            'date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'establishment_id' => 1, 'currency_type_id' => 'ALL',
        ], $this->adminScope());
        $this->assertSame(-1.0, $est1['totals']['transfers_net']);
        $this->assertSame(round($est1['opening_balance'] + $est1['totals']['net'], 2), $est1['totals']['closing_balance']);

        // Removing exchange rates flags missing_exchange_rate when USD account has balance
        $this->db->table('exchange_rates')->delete();
        $noFx = $this->service()->getRealCashFlow([
            'date_start' => '2026-11-01', 'date_end' => '2026-11-30', 'currency_type_id' => 'ALL',
        ], $this->adminScope());
        $this->assertTrue($noFx['missing_exchange_rate']);
    }
}
