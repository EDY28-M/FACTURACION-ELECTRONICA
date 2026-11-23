<?php

namespace Tests\Unit\Treasury;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\DetractionRecord;
use Modules\Treasury\Models\TreasuryMovement;
use PHPUnit\Framework\TestCase;

class DetractionServiceTest extends TestCase
{
    use Phase4Harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function seedSources(): array
    {
        $documentId = $this->insertDocument([
            'number' => '100',
            'total' => 1000.00,
            'detraction' => $this->detractionJson(120.00),
        ]);
        $purchaseId = $this->insertPurchase([
            'number' => '200',
            'total' => 2000.00,
            'detraction' => $this->detractionJson(240.00),
        ]);

        return [$documentId, $purchaseId];
    }

    private function recordFor(string $side): DetractionRecord
    {
        return DetractionRecord::query()
            ->where('side', $side)
            ->where('status', '<>', DetractionRecord::STATUS_SKIPPED)
            ->firstOrFail();
    }

    public function test_sync_creates_receivable_and_payable_records_idempotently_with_due_dates(): void
    {
        [$documentId, $purchaseId] = $this->seedSources();
        // No detraction -> no record; voided source -> no record.
        $this->insertDocument(['number' => '101', 'detraction' => null]);
        $this->insertDocument(['number' => '102', 'state_type_id' => '11', 'detraction' => $this->detractionJson(50.00)]);
        // Zero-amount detraction is ignored.
        $this->insertPurchase(['number' => '201', 'detraction' => $this->detractionJson(0.0)]);

        $service = $this->makeDetractionService();

        $first = $service->syncFromSources();
        $second = $service->syncFromSources();

        $this->assertSame(2, $first['created']);
        $this->assertSame(0, $second['created']);
        // The zero-amount purchase only leaves a `skipped` marker (never listed).
        $this->assertSame(2, DetractionRecord::query()->where('status', '<>', DetractionRecord::STATUS_SKIPPED)->count());
        $this->assertSame(1, DetractionRecord::query()->where('status', DetractionRecord::STATUS_SKIPPED)->count());

        $receivable = $this->recordFor('receivable');
        $this->assertSame($documentId, (int) $receivable->source_id);
        $this->assertSame('037', $receivable->code);
        $this->assertSame(0.12, $receivable->rate);
        $this->assertSame(120.00, $receivable->amount_pen);
        $this->assertSame(1000.00, $receivable->base_amount);
        $this->assertSame('00-123-456789', $receivable->bn_account_number);
        $this->assertSame(DetractionRecord::STATUS_PENDING, $receivable->status);
        // Issued 2026-10-15 -> 5th business day of November 2026.
        $this->assertSame('2026-11-06', $receivable->due_date->format('Y-m-d'));

        $payable = $this->recordFor('payable');
        $this->assertSame($purchaseId, (int) $payable->source_id);
        $this->assertSame(240.00, $payable->amount_pen);
        $this->assertSame('2026-10-20', $payable->registry_date->format('Y-m-d'));
        $this->assertSame('2026-11-06', $payable->due_date->format('Y-m-d'));
    }

    public function test_payable_due_date_moves_to_first_payment_date_when_it_is_earlier(): void
    {
        [, $purchaseId] = $this->seedSources();
        $service = $this->makeDetractionService();
        $service->syncFromSources();

        $this->assertSame('2026-11-06', $this->recordFor('payable')->due_date->format('Y-m-d'));

        // First (partial) payment on Tue 2026-11-03: the deposit is due by then.
        $this->db->table('purchase_payments')->insert([
            'purchase_id' => $purchaseId,
            'payment' => 500.00,
            'date_of_payment' => '2026-11-03',
        ]);
        $result = $service->syncFromSources();

        $this->assertSame(1, $result['updated']);
        $this->assertSame('2026-11-03', $this->recordFor('payable')->due_date->format('Y-m-d'));
    }

    public function test_receivable_net_is_total_minus_detraction_without_breaking_gross_balance_parity(): void
    {
        [$documentId] = $this->seedSources();
        // The customer paid the net amount (1000 - 120) to my normal account.
        $this->db->table('document_payments')->insert([
            'document_id' => $documentId,
            'payment' => 880.00,
            'date_of_payment' => '2026-10-30',
        ]);

        $rows = $this->makeReceivablePayableService()->getReceivables([])['rows'];
        $row = collect($rows)->firstWhere('id', $documentId);

        // Gross balance keeps parity with UnpaidController (total - payments): 120.00 still "unpaid".
        $this->assertSame(120.00, $row['balance']);
        $this->assertSame(120.00, $row['detraction_amount']);
        // Explicit net receivable: nothing is owed to my normal accounts any more.
        $this->assertSame(0.0, $row['net_receivable_amount']);
    }

    public function test_receivable_net_subtracts_detraction_and_unpaid_part_remains(): void
    {
        [$documentId] = $this->seedSources();

        $row = collect($this->makeReceivablePayableService()->getReceivables([])['rows'])->firstWhere('id', $documentId);

        $this->assertSame(1000.00, $row['balance']);
        $this->assertSame(880.00, $row['net_receivable_amount']);
    }

    public function test_payable_net_is_total_minus_detraction(): void
    {
        [, $purchaseId] = $this->seedSources();

        $row = collect($this->makeReceivablePayableService()->getPayables([])['rows'])->firstWhere('id', $purchaseId);

        $this->assertSame(2000.00, $row['balance']);
        $this->assertSame(240.00, $row['detraction_amount']);
        $this->assertSame(1760.00, $row['net_payable_amount']);
    }

    public function test_receivable_deposit_lands_in_the_bn_account_and_can_be_reverted(): void
    {
        $this->seedSources();
        $service = $this->makeDetractionService();
        $service->syncFromSources();
        $record = $this->recordFor('receivable');
        $balanceService = $this->makeBalanceService();

        $updated = $service->registerDeposit($record->id, [
            'date' => '2026-11-05',
            'constancia_number' => 'C-1',
            'operation_number' => 'OP-9',
        ], 1);

        $this->assertSame(DetractionRecord::STATUS_RECEIVED, $updated->status);
        $this->assertSame('C-1', $updated->constancia_number);

        $movement = TreasuryMovement::findOrFail($updated->deposit_movement_id);
        $this->assertSame('detraction_deposit', $movement->kind);
        $this->assertSame(TreasuryMovement::DIRECTION_INFLOW, (int) $movement->direction);
        $this->assertSame(4, (int) $movement->destination_id);
        $this->assertSame(120.00, (float) $movement->amount);
        $this->assertSame(DetractionRecord::class, $movement->source_type);

        $summary = $balanceService->getSummary();
        $this->assertSame('120.00', $summary['totals']['detraction_bn_balance']);

        // A second deposit is refused.
        try {
            $service->registerDeposit($record->id, ['date' => '2026-11-06'], 1);
            $this->fail('Expected ValidationException for a duplicated deposit.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('record', $e->errors());
        }

        // The generic movement void refuses to touch a detraction-owned movement...
        try {
            $this->makeMovementService()->void($movement->id, 'Error de registro', 1);
            $this->fail('Expected ValidationException when voiding a detraction movement directly.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('movement', $e->errors());
        }

        // ...the detraction flow reverts it and restores the balance.
        $reverted = $service->revertDeposit($record->id, 'Depósito duplicado', 1);
        $this->assertSame(DetractionRecord::STATUS_PENDING, $reverted->status);
        $this->assertNull($reverted->deposit_movement_id);
        $this->assertSame('0.00', $balanceService->getSummary()['totals']['detraction_bn_balance']);
        $this->assertSame(TreasuryMovement::STATE_VOIDED, TreasuryMovement::findOrFail($movement->id)->state);
    }

    public function test_payable_deposit_requires_constancia_and_leaves_an_own_account(): void
    {
        $this->seedSources();
        $service = $this->makeDetractionService();
        $service->syncFromSources();
        $record = $this->recordFor('payable');

        try {
            $service->registerDeposit($record->id, ['date' => '2026-11-03', 'bank_account_id' => 1], 1);
            $this->fail('Expected ValidationException for the missing constancia.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('constancia_number', $e->errors());
        }

        // Depositing "from" the BN account is not allowed: that money can only pay SUNAT taxes.
        try {
            $service->registerDeposit($record->id, [
                'date' => '2026-11-03',
                'bank_account_id' => 4,
                'constancia_number' => 'C-77',
            ], 1);
            $this->fail('Expected ValidationException for a BN source account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bank_account_id', $e->errors());
        }

        $updated = $service->registerDeposit($record->id, [
            'date' => '2026-11-03',
            'bank_account_id' => 1,
            'constancia_number' => 'C-77',
        ], 1);

        $this->assertSame(DetractionRecord::STATUS_DEPOSITED, $updated->status);
        $movement = TreasuryMovement::findOrFail($updated->deposit_movement_id);
        $this->assertSame(TreasuryMovement::DIRECTION_OUTFLOW, (int) $movement->direction);
        $this->assertSame(1, (int) $movement->destination_id);

        $byId = collect($this->makeBalanceService()->getSummary()['bank_accounts'])->keyBy('id');
        // 5000 initial - 240 detraction deposited to the supplier's BN account.
        $this->assertSame('4760.00', $byId[1]['balance_currency']);
    }

    public function test_bn_account_blocks_manual_outflows_transfers_and_only_allows_tax_payment_and_release(): void
    {
        $movements = $this->makeMovementService();
        $transfers = $this->makeTransferService();

        // Fund the BN account through a received detraction first.
        $this->seedSources();
        $service = $this->makeDetractionService();
        $service->syncFromSources();
        $service->registerDeposit($this->recordFor('receivable')->id, ['date' => '2026-11-05'], 1);

        foreach (['expense', 'income', 'bank_fee', 'interest'] as $kind) {
            try {
                $movements->createManual([
                    'establishment_id' => 1,
                    'date' => '2026-11-06',
                    'kind' => $kind,
                    'destination_type' => 'bank_account',
                    'destination_id' => 4,
                    'currency_type_id' => 'PEN',
                    'amount' => 10,
                    'treasury_concept_id' => 1,
                    'description' => 'No permitido',
                ], 1);
                $this->fail("Expected ValidationException for {$kind} on the BN account.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('destination_id', $e->errors());
            }
        }

        // Transfers out of and into the BN account are blocked.
        foreach ([['origin_id' => 4, 'target_id' => 1, 'field' => 'origin_id'], ['origin_id' => 1, 'target_id' => 4, 'field' => 'target_id']] as $case) {
            try {
                $transfers->create([
                    'establishment_id' => 1,
                    'date' => '2026-11-06',
                    'origin_type' => 'bank_account',
                    'origin_id' => $case['origin_id'],
                    'target_type' => 'bank_account',
                    'target_id' => $case['target_id'],
                    'amount' => 10,
                    'description' => 'No permitido',
                ], 1);
                $this->fail('Expected ValidationException for a transfer touching the BN account.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($case['field'], $e->errors());
            }
        }

        // tax_payment is the allowed outflow.
        $tax = $movements->createManual([
            'establishment_id' => 1,
            'date' => '2026-11-06',
            'kind' => 'tax_payment',
            'destination_type' => 'bank_account',
            'destination_id' => 4,
            'currency_type_id' => 'PEN',
            'amount' => 20,
            'treasury_concept_id' => 1,
            'description' => 'Pago de IGV con fondos SPOT',
        ], 1);
        $this->assertSame(-1, (int) $tax['movement']->direction);

        // detraction_release moves funds BN -> own account in one atomic pair.
        $release = $service->registerRelease([
            'date' => '2026-11-07',
            'origin_id' => 4,
            'target_id' => 2,
            'amount' => 50,
            'establishment_id' => 1,
            'operation_number' => 'F1697-1',
        ], 1);
        $this->assertSame($release['transfer_group'], $release['out']->transfer_group);
        $this->assertSame($release['transfer_group'], $release['in']->transfer_group);

        $byId = collect($this->makeBalanceService()->getSummary()['bank_accounts'])->keyBy('id');
        // 120 received - 20 taxes - 50 released
        $this->assertSame('50.00', $byId[4]['balance_currency']);
        // 100 initial + 50 released
        $this->assertSame('150.00', $byId[2]['balance_currency']);

        // The release target cannot be another detraction account, nor the origin a regular one.
        try {
            $service->registerRelease(['date' => '2026-11-07', 'origin_id' => 1, 'target_id' => 2, 'amount' => 5, 'establishment_id' => 1], 1);
            $this->fail('Expected ValidationException for a non-BN origin.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('origin_id', $e->errors());
        }
    }

    public function test_overdue_status_is_computed_and_feeds_alerts_and_filters(): void
    {
        $this->seedSources();
        $service = $this->makeDetractionService();
        $service->syncFromSources();

        // As of 2026-11-05 nothing is overdue yet (deadline 2026-11-06).
        $before = $service->listRecords('receivable', ['status' => 'overdue'], '2026-11-05');
        $this->assertSame(0, $before['totals']['by_status']['overdue']['count']);

        // As of 2026-11-10 both are overdue; the stored status stays "pending".
        $payable = $service->listRecords('payable', [], '2026-11-10');
        $this->assertSame(DetractionRecord::STATUS_OVERDUE, $payable['rows'][0]['status']);
        $this->assertSame(DetractionRecord::STATUS_PENDING, $payable['rows'][0]['stored_status']);
        $this->assertSame(4, $payable['rows'][0]['days_overdue']);
        $this->assertSame(DetractionRecord::STATUS_PENDING, $this->recordFor('payable')->status);

        $alerts = $service->getOverdueAlerts([], '2026-11-10');
        $this->assertSame(2, $alerts['count']);
        $this->assertSame(360.00, $alerts['amount_pen']);
        $this->assertCount(2, $alerts['items']);

        $filtered = $service->listRecords('receivable', ['status' => 'pending'], '2026-11-10');
        $this->assertSame(0, $filtered['meta']['total']);
    }

    public function test_listing_flags_rate_that_differs_from_the_versioned_rate_and_hides_voided_sources(): void
    {
        $this->insertDocument([
            'number' => '300',
            'detraction' => $this->detractionJson(100.00, '037', 10.0),
        ]);
        $voided = $this->insertDocument(['number' => '301', 'detraction' => $this->detractionJson(60.00)]);
        $service = $this->makeDetractionService();
        $service->syncFromSources();

        $this->db->table('documents')->where('id', $voided)->update(['state_type_id' => '11']);

        $list = $service->listRecords('receivable', [], '2026-11-10');

        $this->assertSame(1, $list['meta']['total']);
        $row = $list['rows'][0];
        $this->assertTrue($row['rate_differs']);
        $this->assertSame(12.0, $row['expected_rate_percent']);
        $this->assertSame(10.0, $row['rate_percent']);
        $this->assertTrue($row['due_date_is_estimate']);
    }

    public function test_non_admin_scope_isolates_records_by_owner_and_blocks_foreign_actions(): void
    {
        $this->seedSources();
        // A second document owned by another user.
        $this->insertDocument(['number' => '400', 'user_id' => 2, 'detraction' => $this->detractionJson(60.00)]);
        $service = $this->makeDetractionService();
        $service->syncFromSources();

        $adminList = $service->listRecords('receivable', ['is_admin' => true], '2026-11-10');
        $this->assertSame(2, $adminList['meta']['total']);

        $sellerList = $service->listRecords('receivable', ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1], '2026-11-10');
        $this->assertSame(1, $sellerList['meta']['total']);
        $this->assertSame('F001-400', $sellerList['rows'][0]['number_full']);

        $noUser = $service->listRecords('receivable', ['is_admin' => false, 'user_id' => null], '2026-11-10');
        $this->assertSame(0, $noUser['meta']['total']);

        $foreign = $this->recordFor('receivable');
        $this->assertSame(1, (int) $foreign->user_id);
        $this->expectException(AuthorizationException::class);
        $service->registerDeposit($foreign->id, ['date' => '2026-11-05'], 2, ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1]);
    }

    public function test_receivable_deposit_without_a_bn_account_asks_for_one(): void
    {
        $this->db->table('bank_accounts')->where('id', 4)->update(['account_type' => 'checking']);
        $this->seedSources();
        $service = $this->makeDetractionService();
        $service->syncFromSources();

        try {
            $service->registerDeposit($this->recordFor('receivable')->id, ['date' => '2026-11-05'], 1);
            $this->fail('Expected ValidationException when no BN account exists.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bank_account_id', $e->errors());
        }
    }

    // ---- Fix round: W4 / W5 / W6 / W7 / W8 / S1 -------------------------------------------------------

    public function test_unparseable_sources_are_marked_skipped_and_not_reread_by_the_next_sync(): void
    {
        $this->seedSources();
        // Unparseable JSON, missing amount and an invalid issue date: none can become a usable record.
        $this->insertDocument(['number' => '500', 'detraction' => '{not-json']);
        $this->insertDocument(['number' => '501', 'detraction' => json_encode(['detraction_type_id' => '037'])]);
        $this->insertPurchase(['number' => '502', 'date_of_issue' => 'not-a-date', 'detraction' => $this->detractionJson(50.00)]);
        $service = $this->makeDetractionService();

        $first = $service->syncFromSources();
        $this->assertSame(2, $first['created']);
        $this->assertSame(3, $first['skipped']);
        $this->assertSame(5, $first['scanned']);

        // Second pass reads NOTHING: every source (valid or not) already owns a record.
        $second = $service->syncFromSources();
        $this->assertSame(0, $second['scanned']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['skipped']);

        // Markers never reach listings, totals or alerts.
        $this->assertSame(1, $service->listRecords('receivable', [], '2026-12-31')['meta']['total']);
        $this->assertSame(1, $service->listRecords('payable', [], '2026-12-31')['meta']['total']);
        $this->assertSame(2, $service->getOverdueAlerts([], '2026-12-31')['count']);
    }

    public function test_sync_window_advances_past_unparseable_rows(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->insertDocument(['number' => 'bad' . $i, 'detraction' => '{bad']);
        }
        $this->insertDocument(['number' => 'good', 'detraction' => $this->detractionJson(120.00)]);
        $service = $this->makeDetractionService();

        // Limit 3 handles only the first three unparseable rows ...
        $this->assertSame(0, $service->syncFromSources(3)['created']);
        // ... and the next window moves on instead of re-reading them, reaching the valid one.
        $second = $service->syncFromSources(3);
        $this->assertSame(3, $second['scanned']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(1, $second['created']);
    }

    public function test_sync_rethrows_non_duplicate_database_errors_and_counts_only_real_creations(): void
    {
        $this->insertDocument(['number' => '600', 'detraction' => $this->detractionJson(120.00)]);
        $service = $this->makeDetractionService();

        $this->db->statement(
            "create trigger dr_fail before insert on detraction_records begin select raise(abort, 'boom: not a duplicate'); end"
        );
        try {
            $service->syncFromSources();
            $this->fail('Expected the non-duplicate QueryException to be rethrown.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertFalse(\Modules\Treasury\Support\DatabaseErrors::isDuplicateKey($e));
            $this->assertStringContainsString('boom', $e->getMessage());
        }
        $this->db->statement('drop trigger dr_fail');

        $this->assertSame(0, DetractionRecord::query()->count());
        $this->assertSame(1, $service->syncFromSources()['created']);
        $this->assertSame(0, $service->syncFromSources()['created']);
    }

    public function test_duplicate_key_detection_is_driver_aware(): void
    {
        $duplicate = new \Illuminate\Database\QueryException('tenant', 'insert', [], new \PDOException('UNIQUE constraint failed: detraction_records.source_id'));
        $this->assertTrue(\Modules\Treasury\Support\DatabaseErrors::isDuplicateKey($duplicate));

        $mysql = new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'dr_source_side_unique'");
        $mysql->errorInfo = ['23000', 1062, "Duplicate entry 'x'"];
        $this->assertTrue(\Modules\Treasury\Support\DatabaseErrors::isDuplicateKey(new \Illuminate\Database\QueryException('tenant', 'insert', [], $mysql)));

        $notNull = new \PDOException('NOT NULL constraint failed: detraction_records.side');
        $notNull->errorInfo = ['23000', 19, 'NOT NULL constraint failed'];
        $this->assertFalse(\Modules\Treasury\Support\DatabaseErrors::isDuplicateKey(new \Illuminate\Database\QueryException('tenant', 'insert', [], $notNull)));
    }

    public function test_throttled_sync_runs_once_per_window_and_recovers_after_a_failure(): void
    {
        $this->insertDocument(['number' => '700', 'detraction' => $this->detractionJson(120.00)]);
        $service = $this->makeDetractionService();

        $first = $service->syncThrottled(100);
        $this->assertSame(1, $first['created']);

        // New source inside the window is NOT picked up by the throttled caller...
        $this->insertDocument(['number' => '701', 'detraction' => $this->detractionJson(60.00)]);
        $this->assertNull($service->syncThrottled(100));
        $this->assertSame(1, DetractionRecord::query()->count());

        // ... until the window expires.
        $this->app->make('cache')->flush();
        $this->assertSame(1, $service->syncThrottled(100)['created']);

        // A failing sync does not keep the window closed.
        $this->app->make('cache')->flush();
        $this->insertDocument(['number' => '702', 'detraction' => $this->detractionJson(70.00)]);
        $this->db->statement("create trigger dr_fail2 before insert on detraction_records begin select raise(abort, 'boom'); end");
        try {
            $service->syncThrottled(100);
            $this->fail('Expected failure.');
        } catch (\Illuminate\Database\QueryException $e) {
            // expected
        }
        $this->db->statement('drop trigger dr_fail2');
        $this->assertSame(1, $service->syncThrottled(100)['created']);
    }

    public function test_estimated_due_dates_are_flagged_and_get_one_day_of_grace_before_overdue(): void
    {
        $this->seedSources();
        // No holidays configured: both due dates (2026-11-06) are estimates.
        $service = $this->makeDetractionService();
        $service->syncFromSources();

        foreach (['receivable', 'payable'] as $side) {
            // Due 2026-11-06: the day after is NOT yet overdue (a missing holiday could shift the real date).
            $day1 = $service->listRecords($side, [], '2026-11-07');
            $this->assertTrue($day1['rows'][0]['due_date_is_estimate']);
            $this->assertSame(DetractionRecord::STATUS_PENDING, $day1['rows'][0]['status']);
            $this->assertSame(0, $day1['totals']['by_status']['overdue']['count']);
            $this->assertSame(1, $service->listRecords($side, ['status' => 'pending'], '2026-11-07')['meta']['total']);
            $this->assertSame(0, $service->listRecords($side, ['status' => 'overdue'], '2026-11-07')['meta']['total']);

            // Two days past: overdue, consistently in rows, totals and the SQL filters.
            $day2 = $service->listRecords($side, [], '2026-11-08');
            $this->assertSame(DetractionRecord::STATUS_OVERDUE, $day2['rows'][0]['status']);
            $this->assertSame(1, $service->listRecords($side, ['status' => 'overdue'], '2026-11-08')['meta']['total']);
            $this->assertSame(0, $service->listRecords($side, ['status' => 'pending'], '2026-11-08')['meta']['total']);
        }
        $this->assertSame(0, $service->getOverdueAlerts([], '2026-11-07')['count']);
        $this->assertSame(2, $service->getOverdueAlerts([], '2026-11-08')['count']);
    }

    public function test_payable_due_date_is_exact_when_holidays_are_configured_for_its_year(): void
    {
        $this->seedSources();
        // Holidays loaded for 2026 (Mon 2026-11-02): payable deadline moves to Mon 2026-11-09 and is NOT an estimate.
        $service = $this->makeDetractionService(['2026-11-02']);
        $service->syncFromSources();

        $payable = $service->listRecords('payable', [], '2026-11-10');
        $this->assertFalse($payable['rows'][0]['due_date_is_estimate']);
        $this->assertSame('2026-11-09', $payable['rows'][0]['due_date']);
        // Exact date: overdue from the very next day, no grace.
        $this->assertSame(DetractionRecord::STATUS_PENDING, $service->listRecords('payable', [], '2026-11-09')['rows'][0]['status']);
        $this->assertSame(DetractionRecord::STATUS_OVERDUE, $payable['rows'][0]['status']);
        $this->assertSame(1, $service->listRecords('payable', ['status' => 'overdue'], '2026-11-10')['meta']['total']);
        $this->assertSame(0, $service->listRecords('payable', ['status' => 'pending'], '2026-11-10')['meta']['total']);

        // Receivables stay estimates even with holidays loaded.
        $this->assertTrue($service->listRecords('receivable', [], '2026-11-10')['rows'][0]['due_date_is_estimate']);
    }

    public function test_deposit_business_day_comes_from_tax_rates_with_config_fallback(): void
    {
        $service = $this->makeDetractionService();
        // Seeded by migration 000008: 5th business day (2026-11 -> Fri 06).
        $this->assertSame('2026-11-06', $service->computeReceivableExpectedDate('2026-10-15'));

        // Changing the versioned value changes the deadline without touching code (3rd business day = Wed 04).
        $this->db->table('treasury_tax_rates')->where('tax', 'detraction_deposit_business_day')->update(['amount' => 3]);
        $fresh = $this->makeDetractionService();
        $this->assertSame('2026-11-04', $fresh->computeReceivableExpectedDate('2026-10-15'));

        // Without the row, the config fallback applies.
        $this->db->table('treasury_tax_rates')->where('tax', 'detraction_deposit_business_day')->delete();
        $this->assertSame('2026-11-06', $this->makeDetractionService()->computeReceivableExpectedDate('2026-10-15'));
    }

    public function test_non_admin_without_establishment_cannot_access_null_establishment_records(): void
    {
        $this->insertDocument(['number' => '800', 'user_id' => 2, 'establishment_id' => null, 'detraction' => $this->detractionJson(120.00)]);
        $service = $this->makeDetractionService();
        $service->syncFromSources();
        $record = $this->recordFor('receivable');
        $this->assertNull($record->establishment_id);

        foreach ([null, 0] as $establishment) {
            $scope = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => $establishment];
            try {
                $service->assertRecordAccess($record, $scope);
                $this->fail('Expected AuthorizationException for establishment ' . var_export($establishment, true));
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('permiso', $e->getMessage());
            }
            try {
                $service->registerDeposit($record->id, ['date' => '2026-11-05', 'bank_account_id' => 4], 2, $scope);
                $this->fail('Expected AuthorizationException on deposit.');
            } catch (AuthorizationException $e) {
                $this->assertTrue(true);
            }
        }

        // Admin is unaffected.
        $service->assertRecordAccess($record, ['is_admin' => true]);
    }

    public function test_non_admin_cannot_credit_or_revert_another_establishments_bn_account(): void
    {
        // BN account 4 belongs to establishment 1; a second BN account belongs to establishment 2.
        $this->db->table('bank_accounts')->insert([
            'id' => 5, 'bank_id' => 2, 'description' => 'BN Sucursal', 'number' => '00-999-000111', 'currency_type_id' => 'PEN',
            'status' => 1, 'initial_balance' => 0, 'show_in_documents' => 0, 'account_type' => 'detraction_bn', 'itf_exempt' => 1, 'establishment_id' => 2,
        ]);
        $this->insertDocument(['number' => '810', 'user_id' => 2, 'establishment_id' => 2, 'detraction' => json_encode([
            'detraction_type_id' => '037', 'percentage' => 12, 'amount' => 120.00, 'bank_account' => '00-123-456789',
        ])]);
        $service = $this->makeDetractionService();
        $service->syncFromSources();
        $record = $this->recordFor('receivable');
        $this->assertSame(2, (int) $record->establishment_id);

        // Seller of establishment 2: the invoice's BN number points at account 4 (establishment 1). Auto-resolution
        // must be filtered by establishment, so it can never pick account 4; with a single eligible account (5)
        // it resolves to the seller's own establishment.
        $scope2 = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 2];
        $done = $service->registerDeposit($record->id, ['date' => '2026-11-05'], 2, $scope2);
        $movement = TreasuryMovement::query()->findOrFail($done->deposit_movement_id);
        $this->assertSame(5, (int) $movement->destination_id);

        // Explicit foreign account is refused by the service itself (not only by the controller).
        $service->revertDeposit($record->id, 'prueba', 1, ['is_admin' => true]);
        try {
            $service->registerDeposit($record->id, ['date' => '2026-11-05', 'bank_account_id' => 4], 2, $scope2);
            $this->fail('Expected AuthorizationException for a foreign account.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otra sede', $e->getMessage());
        }

        // Revert: the deposit credited account 4 (admin did it); the establishment-2 seller cannot revert it.
        $done = $service->registerDeposit($record->id, ['date' => '2026-11-05', 'bank_account_id' => 4], 1, ['is_admin' => true]);
        $this->assertSame(4, (int) TreasuryMovement::query()->findOrFail($done->deposit_movement_id)->destination_id);
        try {
            $service->revertDeposit($record->id, 'intento', 2, $scope2);
            $this->fail('Expected AuthorizationException when reverting a foreign account deposit.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otra sede', $e->getMessage());
        }
        $this->assertSame(DetractionRecord::STATUS_RECEIVED, $record->fresh()->status);
    }

    public function test_default_bn_resolution_never_picks_another_establishments_account_for_a_non_admin(): void
    {
        // Only BN account is account 4 (establishment 1). A seller of establishment 2 gets "no account found".
        $this->insertDocument(['number' => '820', 'user_id' => 2, 'establishment_id' => 2, 'detraction' => $this->detractionJson(120.00)]);
        $service = $this->makeDetractionService();
        $service->syncFromSources();
        $record = $this->recordFor('receivable');

        try {
            $service->registerDeposit($record->id, ['date' => '2026-11-05'], 2, ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 2]);
            $this->fail('Expected ValidationException: no eligible BN account.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bank_account_id', $e->errors());
        }
        $this->assertSame(0, TreasuryMovement::query()->count());
    }
}
