<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\Cash;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Pos\Models\CashTransaction;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\CashCountService;
use PHPUnit\Framework\TestCase;

class CashCountServiceTest extends TestCase
{
    use Phase4Harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();

        $this->db->statement('create table cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text, description text null, payment numeric)');

        require_once __DIR__ . '/../../../database/migrations/tenant/2026_11_23_000012_tenant_create_treasury_cash_counts_table.php';
        (new \TenantCreateTreasuryCashCountsTable())->up();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function service(): CashCountService
    {
        return new CashCountService($this->makeBalanceService());
    }

    private function seedCash(int $userId, float $openingTxAmount = 500.00): int
    {
        $cashId = (int) $this->db->table('cash')->insertGetId([
            'user_id' => $userId,
            'date_opening' => '2026-11-20',
            'time_opening' => '08:00:00',
            'beginning_balance' => $openingTxAmount,
            'final_balance' => 0,
            'income' => 0,
            'state' => 1,
            'reference_number' => 'POS-' . $userId,
        ]);

        if ($openingTxAmount > 0) {
            $ctId = (int) $this->db->table('cash_transactions')->insertGetId([
                'cash_id' => $cashId,
                'date' => '2026-11-20',
                'description' => 'Apertura',
                'payment' => $openingTxAmount,
            ]);
            $this->db->table('global_payments')->insert([
                'destination_id' => $cashId,
                'destination_type' => Cash::class,
                'payment_id' => $ctId,
                'payment_type' => CashTransaction::class,
            ]);
        }

        return $cashId;
    }

    public function test_default_denominations_come_from_config_with_fallback(): void
    {
        $default = CashCountService::defaultDenominations();
        $this->assertSame(200.0, $default[0]['value']);
        $this->assertSame('bill', $default[0]['type']);
        $this->assertSame('coin', end($default)['type']);

        $this->app['config']->set('treasury.cash_count.denominations', [20, 0.5]);
        $custom = CashCountService::defaultDenominations();
        $this->assertSame([20.0, 0.5], array_column($custom, 'value'));
    }

    public function test_migration_is_idempotent_and_unmigrated_table_fails_with_validation_exception(): void
    {
        $migration = new \TenantCreateTreasuryCashCountsTable();
        // Calling up() again is a safe no-op
        $migration->up();
        $this->assertTrue($this->db->getSchemaBuilder()->hasTable('treasury_cash_counts'));

        // down() drops table cleanly -> service returns clean 422 ValidationException
        $migration->down();
        $this->assertFalse($this->db->getSchemaBuilder()->hasTable('treasury_cash_counts'));

        try {
            $this->service()->listCounts([], ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1]);
            $this->fail('Expected ValidationException when treasury_cash_counts table is missing.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cash_id', $e->errors());
        }

        // Re-running up() restores the table cleanly
        $migration->up();
        $this->assertTrue($this->db->getSchemaBuilder()->hasTable('treasury_cash_counts'));
    }

    public function test_create_computes_balances_server_side_and_classifies_balanced_surplus_and_shortage(): void
    {
        $cashId = $this->seedCash(1, 350.00);

        // Add active TreasuryMovement inflow (+50.00) -> expected system_balance = 400.00
        TreasuryMovement::create([
            'establishment_id' => 1,
            'date' => '2026-11-20',
            'kind' => 'income',
            'direction' => 1,
            'destination_type' => Cash::class,
            'destination_id' => $cashId,
            'currency_type_id' => 'PEN',
            'amount' => 50.00,
            'amount_pen' => 50.00,
            'treasury_concept_id' => 4,
            'description' => 'Reposición caja',
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => 1,
        ]);

        $scope = ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];
        $bal = $this->service()->getSystemBalance($cashId, $scope);
        $this->assertSame(400.0, $bal['system_balance']);

        // 1) Balanced count (2*100 + 4*50 = 400.00) — even if caller sends forged system_balance/counted_balance
        $balanced = $this->service()->create([
            'cash_id' => $cashId,
            'system_balance' => 0,
            'counted_balance' => 9999,
            'denominations' => [
                ['value' => 100, 'qty' => 2],
                ['value' => 50, 'qty' => 4],
            ],
            'notes' => 'Cierre exacto',
        ], 1, $scope);

        $this->assertSame(400.0, $balanced->system_balance);
        $this->assertSame(400.0, $balanced->counted_balance);
        $this->assertSame(0.0, $balanced->difference);
        $this->assertSame('Cuadrado', $this->service()->formatCountRow($balanced)['status_label']);

        // 2) Surplus count (410.50 -> +10.50)
        $surplus = $this->service()->create([
            'cash_id' => $cashId,
            'denominations' => [
                ['value' => 200, 'qty' => 2],
                ['value' => 10, 'qty' => 1],
                ['value' => 0.50, 'qty' => 1],
            ],
        ], 1, $scope);
        $this->assertSame(410.5, $surplus->counted_balance);
        $this->assertSame(10.5, $surplus->difference);
        $this->assertSame('Sobrante', $this->service()->formatCountRow($surplus)['status_label']);

        // 3) Shortage count (380.00 -> -20.00)
        $shortage = $this->service()->create([
            'cash_id' => $cashId,
            'denominations' => [
                ['value' => 100, 'qty' => 3],
                ['value' => 20, 'qty' => 4],
            ],
        ], 1, $scope);
        $this->assertSame(380.0, $shortage->counted_balance);
        $this->assertSame(-20.0, $shortage->difference);
        $this->assertSame('Faltante', $this->service()->formatCountRow($shortage)['status_label']);

        // Filtering by status works
        $onlyShortages = $this->service()->listCounts(['status' => 'shortage'], $scope);
        $this->assertCount(1, $onlyShortages['rows']);
        $this->assertSame((int) $shortage->id, $onlyShortages['rows'][0]['id']);
    }

    public function test_non_admin_cannot_access_or_count_another_users_cash_and_null_establishment_fails_closed(): void
    {
        $cashUser1 = $this->seedCash(1, 100.00);
        $cashUser2 = $this->seedCash(2, 200.00);

        $adminScope = ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];
        $sellerScope = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1];
        $noEstScope = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => null];

        $countAdmin = $this->service()->create([
            'cash_id' => $cashUser1,
            'denominations' => [['value' => 100, 'qty' => 1]],
        ], 1, $adminScope);

        $countSeller = $this->service()->create([
            'cash_id' => $cashUser2,
            'denominations' => [['value' => 100, 'qty' => 2]],
        ], 2, $sellerScope);

        // Seller list sees ONLY own count
        $sellerList = $this->service()->listCounts([], $sellerScope);
        $this->assertSame([(int) $countSeller->id], array_column($sellerList['rows'], 'id'));

        // Seller cannot read admin's cash count
        try {
            $this->service()->findForUser((int) $countAdmin->id, $sellerScope);
            $this->fail('Seller must be denied on another user cash count.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otro usuario', $e->getMessage());
        }

        // Seller cannot fetch system balance of admin's cash
        try {
            $this->service()->getSystemBalance($cashUser1, $sellerScope);
            $this->fail('Seller must be denied on another user cash balance.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otro usuario', $e->getMessage());
        }

        // User without establishment sees empty list and cannot count own cash
        $this->assertSame([], $this->service()->listCounts([], $noEstScope)['rows']);
        $this->expectException(AuthorizationException::class);
        $this->service()->getSystemBalance($cashUser2, $noEstScope);
    }

    public function test_negative_or_fractional_denomination_quantity_is_rejected(): void
    {
        $cashId = $this->seedCash(1, 100.00);
        $scope = ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];

        try {
            $this->service()->create([
                'cash_id' => $cashId,
                'denominations' => [['value' => 100, 'qty' => -1]],
            ], 1, $scope);
            $this->fail('Negative quantity must fail validation.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('denominations.0.qty', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $this->service()->create([
            'cash_id' => $cashId,
            'denominations' => [['value' => 100, 'qty' => 1.5]],
        ], 1, $scope);
    }

    public function test_duplicate_denominations_future_date_and_amount_bounds_are_rejected(): void
    {
        $cashId = $this->seedCash(1, 100.00);
        $scope = ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];

        // Duplicate denomination value
        try {
            $this->service()->create([
                'cash_id' => $cashId,
                'denominations' => [
                    ['value' => 50, 'qty' => 1],
                    ['value' => 50.00, 'qty' => 2],
                ],
            ], 1, $scope);
            $this->fail('Duplicate denomination values must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('denominations', $e->errors());
        }

        // Future counted_at
        try {
            $this->service()->create([
                'cash_id' => $cashId,
                'counted_at' => date('Y-m-d H:i:s', strtotime('+2 days')),
                'denominations' => [['value' => 100, 'qty' => 1]],
            ], 1, $scope);
            $this->fail('Future counted_at must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('counted_at', $e->errors());
        }

        // Excessively large qty (> 1,000,000)
        $this->expectException(ValidationException::class);
        $this->service()->create([
            'cash_id' => $cashId,
            'denominations' => [['value' => 100, 'qty' => 1000001]],
        ], 1, $scope);
    }

    public function test_system_balance_excludes_payments_registered_after_counted_at_on_the_same_day(): void
    {
        $cashId = $this->seedCash(1, 500.00);

        $addPayment = function (float $amount, string $registeredAt) use ($cashId): void {
            $docId = $this->insertDocument(['date_of_issue' => '2026-11-20', 'total' => $amount]);
            $paymentId = (int) $this->db->table('document_payments')->insertGetId([
                'document_id' => $docId, 'payment' => $amount, 'date_of_payment' => '2026-11-20',
            ]);
            $this->db->table('global_payments')->insert([
                'destination_id' => $cashId, 'destination_type' => Cash::class,
                'payment_id' => $paymentId, 'payment_type' => \App\Models\Tenant\DocumentPayment::class, 'created_at' => $registeredAt,
            ]);
        };
        $addPayment(50.00, '2026-11-20 09:00:00');   // before the count
        $addPayment(100.00, '2026-11-20 15:00:00');  // after the count, same day

        $count = $this->service()->create([
            'cash_id' => $cashId,
            'counted_at' => '2026-11-20 10:00:00',
            'denominations' => [['value' => 100, 'qty' => 5], ['value' => 50, 'qty' => 1]],
        ], 1, ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1]);

        // 500 opening + 50 registered before 10:00; the 100 registered at 15:00 is not in the till yet.
        $this->assertEquals(550.00, (float) $count->system_balance);
        $this->assertEquals(0.00, (float) $count->difference);

        // Without counted_at the current balance includes everything.
        $this->assertEquals(650.00, $this->service()->getSystemBalance($cashId, ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1])['system_balance']);
    }
}
