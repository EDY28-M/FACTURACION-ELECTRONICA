<?php

namespace Tests\Unit\Treasury;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Models\TreasuryInstrumentEvent;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\InstrumentService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7: cheques y letras. State machine, money effects through MovementService, deadlines and alerts.
 */
class InstrumentServiceTest extends TestCase
{
    use Phase4Harness;

    private const ADMIN = ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-12-08 (Inmaculada Concepcion) is a national holiday: it moves the protest deadline.
        $this->bootHarness(['2026-12-08']);
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function service(array $holidays = ['2026-12-08']): InstrumentService
    {
        return $this->makeInstrumentService($holidays);
    }

    private function payload(string $type, array $overrides = []): array
    {
        $isInflow = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_BILL_RECEIVABLE], true);
        $isCheque = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_CHEQUE_ISSUED], true);

        return array_merge([
            'type' => $type,
            'number' => 'N-' . random_int(100000, 999999),
            'amount' => 1500.00,
            'currency_type_id' => 'PEN',
            'issue_date' => $isCheque ? '2026-11-10' : '2026-09-01',
            'due_date' => $isCheque ? null : '2026-10-01',
            'bank_id' => 1,
            'bank_account_id' => $type === TreasuryInstrument::TYPE_CHEQUE_ISSUED ? 1 : null,
            'person_id' => $isInflow ? 1 : 10,
        ], $overrides);
    }

    private function create(string $type, array $overrides = [], array $scope = self::ADMIN, int $userId = 1): TreasuryInstrument
    {
        return $this->service()->create($this->payload($type, $overrides), $userId, $scope);
    }

    /**
     * Drives a new instrument through the shortest valid path until it reaches $target (BFS over TRANSITIONS).
     */
    private function createAt(string $type, string $target, array $overrides = []): TreasuryInstrument
    {
        $instrument = $this->create($type, $overrides);
        $path = $this->pathTo($type, InstrumentService::initialStatus($type), $target);
        $this->assertNotNull($path, "No path to {$target} for {$type}");

        foreach ($path as $step) {
            $instrument = $this->service()->transition((int) $instrument->id, $step, [
                'bank_account_id' => 1,
                'notes' => 'Motivo de prueba',
            ], 1, self::ADMIN)['instrument'];
        }

        return $instrument;
    }

    private function pathTo(string $type, string $from, string $target): ?array
    {
        $queue = [[$from, []]];
        $seen = [$from => true];
        while ($queue) {
            [$status, $path] = array_shift($queue);
            if ($status === $target) {
                return $path;
            }
            foreach (InstrumentService::TRANSITIONS[$type][$status] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $seen[$next] = true;
                    $queue[] = [$next, array_merge($path, [$next])];
                }
            }
        }

        return null;
    }

    private function balance(int $accountId = 1): float
    {
        return (float) $this->makeBalanceService()->getBankAccountBalanceAsOf($accountId, date('Y-m-d'))['balance'];
    }

    // ---------------------------------------------------------------------------------------------------------
    // State machine
    // ---------------------------------------------------------------------------------------------------------

    public function test_initial_status_and_creation_event_per_type(): void
    {
        foreach (TreasuryInstrument::TYPES as $type) {
            $instrument = $this->create($type);
            $expected = $type === TreasuryInstrument::TYPE_CHEQUE_RECEIVED ? 'delivered' : 'issued';
            $this->assertSame($expected, $instrument->status, $type);

            $events = TreasuryInstrumentEvent::where('instrument_id', $instrument->id)->get();
            $this->assertCount(1, $events);
            $this->assertSame('created', $events[0]->action);
            $this->assertNull($events[0]->from_status);
            $this->assertSame($expected, $events[0]->to_status);
        }
    }

    public function test_every_allowed_transition_per_type_succeeds_and_is_recorded_in_the_history(): void
    {
        $checked = 0;
        foreach (InstrumentService::TRANSITIONS as $type => $byFrom) {
            foreach ($byFrom as $from => $targets) {
                foreach ($targets as $to) {
                    $instrument = $this->createAt($type, $from);
                    $this->assertSame($from, $instrument->status);

                    $result = $this->service()->transition((int) $instrument->id, $to, [
                        'bank_account_id' => 1,
                        'notes' => 'Motivo de prueba',
                    ], 1, self::ADMIN);

                    $this->assertSame($to, $result['instrument']->status, "{$type}: {$from} -> {$to}");

                    $last = TreasuryInstrumentEvent::where('instrument_id', $instrument->id)->orderByDesc('id')->first();
                    $this->assertSame($from, $last->from_status);
                    $this->assertSame($to, $last->to_status);
                    $checked++;
                }
            }
        }

        // 5 + 6 + 9 + 6 edges, plus the review additions: collected -> delivered on the four types and
        // bounced -> deposited on cheque_received (5 more).
        $this->assertSame(31, $checked);
    }

    public function test_every_refused_transition_per_type_is_rejected_with_a_spanish_message_and_changes_nothing(): void
    {
        $checked = 0;
        foreach (TreasuryInstrument::TYPES as $type) {
            foreach (TreasuryInstrument::STATUSES as $from) {
                foreach (TreasuryInstrument::STATUSES as $to) {
                    if (in_array($to, InstrumentService::TRANSITIONS[$type][$from] ?? [], true)) {
                        continue;
                    }

                    $instrument = $this->create($type);
                    // Force the source status directly: the refusal must not depend on how it was reached.
                    $this->db->table('treasury_instruments')->where('id', $instrument->id)->update(['status' => $from]);
                    $movementsBefore = TreasuryMovement::count();

                    try {
                        $this->service()->transition((int) $instrument->id, $to, [
                            'bank_account_id' => 1,
                            'notes' => 'Motivo de prueba',
                        ], 1, self::ADMIN);
                        $this->fail("Expected refusal: {$type} {$from} -> {$to}");
                    } catch (ValidationException $e) {
                        $this->assertStringContainsString('no se puede pasar', implode(' ', $e->errors()['status']));
                    }

                    $this->assertSame($from, TreasuryInstrument::find($instrument->id)->status);
                    $this->assertSame($movementsBefore, TreasuryMovement::count());
                    $checked++;
                }
            }
        }

        // 4 types x 49 pairs minus the 31 allowed edges.
        $this->assertSame(4 * 49 - 31, $checked);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Money effects
    // ---------------------------------------------------------------------------------------------------------

    public function test_collecting_each_type_creates_one_movement_with_the_right_direction_account_and_balance(): void
    {
        $cases = [
            TreasuryInstrument::TYPE_CHEQUE_RECEIVED => [TreasuryMovement::DIRECTION_INFLOW, 'income', 'Cobro de cheques y letras', '007'],
            TreasuryInstrument::TYPE_CHEQUE_ISSUED => [TreasuryMovement::DIRECTION_OUTFLOW, 'expense', 'Pago de cheques y letras', '007'],
            TreasuryInstrument::TYPE_BILL_RECEIVABLE => [TreasuryMovement::DIRECTION_INFLOW, 'income', 'Cobro de cheques y letras', '999'],
            TreasuryInstrument::TYPE_BILL_PAYABLE => [TreasuryMovement::DIRECTION_OUTFLOW, 'expense', 'Pago de cheques y letras', '999'],
        ];

        foreach ($cases as $type => [$direction, $kind, $conceptName, $code]) {
            $before = $this->balance();
            $instrument = $this->createAt($type, 'collected', ['number' => 'COB-' . $type]);

            $this->assertNotNull($instrument->movement_id, $type);
            $movement = TreasuryMovement::find($instrument->movement_id);
            $this->assertSame($direction, $movement->direction, $type);
            $this->assertSame($kind, $movement->kind, $type);
            $this->assertSame(1, (int) $movement->destination_id);
            $this->assertSame(1500.0, (float) $movement->amount);
            $this->assertSame('COB-' . $type, $movement->operation_number);
            $this->assertSame($code, $movement->sunat_payment_code);
            $this->assertSame(TreasuryInstrument::class, $movement->source_type);
            $this->assertSame((int) $instrument->id, (int) $movement->source_id);
            $this->assertSame($conceptName, $this->db->table('treasury_concepts')->where('id', $movement->treasury_concept_id)->value('description'));
            $this->assertSame(1, TreasuryMovement::where('source_type', TreasuryInstrument::class)->where('source_id', $instrument->id)->count());

            $this->assertEqualsWithDelta($before + $direction * 1500.0, $this->balance(), 0.001, $type);
        }
    }

    public function test_deposit_and_protest_move_no_money(): void
    {
        $before = $this->balance();
        $cheque = $this->createAt(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, 'deposited');
        $bill = $this->createAt(TreasuryInstrument::TYPE_BILL_RECEIVABLE, 'protested');

        $this->assertNull($cheque->movement_id);
        $this->assertNull($bill->movement_id);
        $this->assertSame(1, (int) $cheque->bank_account_id);
        $this->assertSame(0, TreasuryMovement::count());
        $this->assertEqualsWithDelta($before, $this->balance(), 0.001);
    }

    public function test_bounced_after_collection_voids_the_movement_and_restores_the_balance(): void
    {
        foreach ([TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_CHEQUE_ISSUED] as $type) {
            $before = $this->balance();
            $instrument = $this->createAt($type, 'collected', ['number' => 'REB-' . $type]);
            $movementId = (int) $instrument->movement_id;
            $this->assertNotEqualsWithDelta($before, $this->balance(), 0.001);

            $result = $this->service()->transition((int) $instrument->id, 'bounced', ['notes' => 'Sin fondos'], 1, self::ADMIN);

            $this->assertSame('bounced', $result['instrument']->status);
            $this->assertNull($result['instrument']->movement_id);
            $voided = TreasuryMovement::find($movementId);
            $this->assertSame('voided', $voided->state);
            $this->assertStringContainsString('Sin fondos', $voided->void_reason);
            $this->assertEqualsWithDelta($before, $this->balance(), 0.001, $type);

            $event = TreasuryInstrumentEvent::where('instrument_id', $instrument->id)->orderByDesc('id')->first();
            $this->assertSame($movementId, (int) $event->movement_id);
            $this->assertSame('Sin fondos', $event->notes);
        }
    }

    public function test_bounced_from_deposited_has_no_movement_to_reverse(): void
    {
        $instrument = $this->createAt(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, 'deposited');
        $result = $this->service()->transition((int) $instrument->id, 'bounced', ['notes' => 'Cuenta cerrada'], 1, self::ADMIN);

        $this->assertSame('bounced', $result['instrument']->status);
        $this->assertSame(0, TreasuryMovement::count());
    }

    public function test_bounced_in_a_closed_reconciliation_is_refused_and_rolls_everything_back(): void
    {
        $instrument = $this->createAt(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, 'collected');
        $this->db->table('treasury_movements')->where('id', $instrument->movement_id)->update(['reconciled_at' => '2026-11-20 10:00:00']);

        try {
            $this->service()->transition((int) $instrument->id, 'bounced', ['notes' => 'Sin fondos'], 1, self::ADMIN);
            $this->fail('Expected the reconciliation guard to refuse the bounce.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('conciliación', implode(' ', $e->errors()['movement']));
        }

        $fresh = TreasuryInstrument::find($instrument->id);
        $this->assertSame('collected', $fresh->status);
        $this->assertSame((int) $instrument->movement_id, (int) $fresh->movement_id);
        $this->assertSame('active', TreasuryMovement::find($instrument->movement_id)->state);
    }

    public function test_an_instrument_movement_cannot_be_voided_outside_the_instrument_flow(): void
    {
        $instrument = $this->createAt(TreasuryInstrument::TYPE_BILL_PAYABLE, 'collected');

        try {
            $this->makeMovementService()->void((int) $instrument->movement_id, 'Anulacion directa', 1);
            $this->fail('Expected refusal.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Cheques y letras', implode(' ', $e->errors()['movement']));
        }

        $this->assertSame('active', TreasuryMovement::find($instrument->movement_id)->state);
    }

    public function test_global_payments_are_never_touched(): void
    {
        $this->createAt(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, 'collected');
        $this->createAt(TreasuryInstrument::TYPE_BILL_PAYABLE, 'collected');

        $this->assertSame(0, $this->db->table('global_payments')->count());
    }

    public function test_collecting_requires_an_account_that_matches_currency_and_is_not_the_bn_detraction_account(): void
    {
        $bill = $this->createAt(TreasuryInstrument::TYPE_BILL_PAYABLE, 'delivered');

        foreach ([
            [null, 'bank_account_id'],
            [4, 'bank_account_id'], // BN detraction account
        ] as [$accountId, $field]) {
            try {
                $this->service()->transition((int) $bill->id, 'collected', ['bank_account_id' => $accountId], 1, self::ADMIN);
                $this->fail('Expected refusal for account ' . var_export($accountId, true));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        // USD account for a PEN instrument.
        $this->db->table('bank_accounts')->insert([
            'id' => 7, 'bank_id' => 1, 'description' => 'BCP USD', 'number' => '191-777', 'currency_type_id' => 'USD', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1,
        ]);
        try {
            $this->service()->transition((int) $bill->id, 'collected', ['bank_account_id' => 7], 1, self::ADMIN);
            $this->fail('Expected currency mismatch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('moneda', implode(' ', $e->errors()['bank_account_id']));
        }

        $this->assertSame('delivered', TreasuryInstrument::find($bill->id)->status);
        $this->assertSame(0, TreasuryMovement::count());
    }

    public function test_double_collection_from_a_stale_view_creates_a_single_movement(): void
    {
        $instrument = $this->createAt(TreasuryInstrument::TYPE_CHEQUE_ISSUED, 'delivered');
        $stale = TreasuryInstrument::find($instrument->id);

        $this->service()->transition((int) $instrument->id, 'collected', [], 1, self::ADMIN);

        try {
            // A second request that still believed the cheque was delivered re-reads it under lock and is refused.
            $this->service()->transition((int) $stale->id, 'collected', [], 1, self::ADMIN);
            $this->fail('Expected refusal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(1, TreasuryMovement::count());
    }

    public function test_transitions_and_updates_lock_the_instrument_row_before_touching_accounts(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../modules/Treasury/Services/InstrumentService.php');
        $this->assertSame(2, substr_count($source, 'TreasuryInstrument::whereKey($id)->lockForUpdate()->firstOrFail()'));
    }

    public function test_bounce_and_void_require_a_reason(): void
    {
        $collected = $this->createAt(TreasuryInstrument::TYPE_CHEQUE_ISSUED, 'collected');
        $fresh = $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE);

        foreach ([[$collected, 'bounced'], [$fresh, 'voided']] as [$instrument, $to]) {
            try {
                $this->service()->transition((int) $instrument->id, $to, ['notes' => ' '], 1, self::ADMIN);
                $this->fail('Expected refusal without a reason.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('notes', $e->errors());
            }
        }

        $this->assertSame('collected', TreasuryInstrument::find($collected->id)->status);
    }

    public function test_voiding_frees_the_number_and_stores_the_audit_trail(): void
    {
        $instrument = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['number' => 'L-0001']);
        $voided = $this->service()->transition((int) $instrument->id, 'voided', ['notes' => 'Error de digitación'], 1, self::ADMIN)['instrument'];

        $this->assertSame('voided', $voided->status);
        $this->assertSame('Error de digitación', $voided->void_reason);
        $this->assertSame(1, (int) $voided->voided_by);
        $this->assertNotNull($voided->voided_at);
        $this->assertNull($voided->active_key);

        // The same number can be registered again.
        $again = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['number' => 'L-0001']);
        $this->assertSame('issued', $again->status);
    }

    public function test_duplicate_live_numbers_are_refused(): void
    {
        $this->create(TreasuryInstrument::TYPE_CHEQUE_ISSUED, ['number' => '000123']);

        try {
            $this->create(TreasuryInstrument::TYPE_CHEQUE_ISSUED, ['number' => '000123']);
            $this->fail('Expected duplicate refusal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('number', $e->errors());
        }

        // Same number on another account is a different cheque.
        $other = $this->create(TreasuryInstrument::TYPE_CHEQUE_ISSUED, ['number' => '000123', 'bank_account_id' => 2]);
        $this->assertNotNull($other->id);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Deadlines
    // ---------------------------------------------------------------------------------------------------------

    public function test_cheque_presentation_deadline_comes_from_the_versioned_rate(): void
    {
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-11-10']);
        $this->assertSame('2026-12-10', $cheque->presentation_deadline->format('Y-m-d'));
        $this->assertNull($cheque->protest_deadline);
        // Non-deferred: due date = issue date.
        $this->assertSame('2026-11-10', $cheque->due_date->format('Y-m-d'));

        // Change the versioned rate: no code change needed.
        $this->db->table('treasury_tax_rates')->where('tax', 'cheque_presentation_days')->update(['amount' => 20]);
        $other = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-11-10']);
        $this->assertSame('2026-11-30', $other->presentation_deadline->format('Y-m-d'));
    }

    public function test_cheque_rate_falls_back_to_config_only_when_the_row_is_missing(): void
    {
        $this->db->table('treasury_tax_rates')->where('tax', 'cheque_presentation_days')->delete();
        app('config')->set('treasury.instruments.cheque_presentation_days_fallback', 25);

        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_ISSUED, ['issue_date' => '2026-11-10']);
        $this->assertSame('2026-12-05', $cheque->presentation_deadline->format('Y-m-d'));
    }

    public function test_deferred_cheque_counts_presentation_from_the_payment_date_and_caps_the_term(): void
    {
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, [
            'issue_date' => '2026-11-10', 'due_date' => '2026-12-05', 'is_deferred' => true,
        ]);
        $this->assertTrue($cheque->is_deferred);
        $this->assertSame('2026-12-05', $cheque->due_date->format('Y-m-d'));
        $this->assertSame('2027-01-04', $cheque->presentation_deadline->format('Y-m-d'));

        foreach (['2026-11-10', '2026-12-11'] as $badDue) {
            try {
                $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, [
                    'issue_date' => '2026-11-10', 'due_date' => $badDue, 'is_deferred' => true,
                ]);
                $this->fail("Expected refusal for due {$badDue}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('due_date', $e->errors());
            }
        }
    }

    public function test_bill_protest_deadline_uses_business_days_and_the_holiday_table(): void
    {
        // 15 business days after Fri 2026-11-27; the 8 Dec holiday pushes it from 18 Dec to 21 Dec.
        $bill = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-11-01', 'due_date' => '2026-11-27']);
        $this->assertSame('2026-12-21', $bill->protest_deadline->format('Y-m-d'));
        $this->assertNull($bill->presentation_deadline);
        $this->assertFalse($bill->deadline_is_estimate);

        // Without holidays loaded the year is unknown: 18 Dec and flagged as an estimate.
        $noHolidays = $this->makeInstrumentService([])->create(
            $this->payload(TreasuryInstrument::TYPE_BILL_PAYABLE, ['issue_date' => '2026-11-01', 'due_date' => '2026-11-27']),
            1,
            self::ADMIN
        );
        $this->assertSame('2026-12-18', $noHolidays->protest_deadline->format('Y-m-d'));
        $this->assertTrue($noHolidays->deadline_is_estimate);
    }

    public function test_bill_protest_deadline_can_be_counted_in_calendar_days(): void
    {
        app('config')->set('treasury.instruments.protest_day_type', 'calendar');
        $bill = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-11-01', 'due_date' => '2026-11-27']);

        $this->assertSame('2026-12-12', $bill->protest_deadline->format('Y-m-d'));
        $this->assertFalse($bill->deadline_is_estimate);
    }

    public function test_protest_before_maturity_is_refused_and_late_protest_warns(): void
    {
        $bill = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-09-01', 'due_date' => '2026-11-27']);
        $bill = $this->service()->transition((int) $bill->id, 'delivered', [], 1, self::ADMIN)['instrument'];

        try {
            $this->service()->transition((int) $bill->id, 'protested', ['date' => '2026-11-20'], 1, self::ADMIN);
            $this->fail('Expected refusal before maturity.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }

        // A bill whose protest deadline already passed: the protest is recorded with a warning, not refused.
        $late = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-08-01', 'due_date' => '2026-09-01']);
        $late = $this->service()->transition((int) $late->id, 'delivered', [], 1, self::ADMIN)['instrument'];
        $result = $this->service()->transition((int) $late->id, 'protested', ['date' => date('Y-m-d')], 1, self::ADMIN);

        $this->assertSame('protested', $result['instrument']->status);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('plazo legal', $result['warnings'][0]);
    }

    public function test_presenting_a_stale_cheque_warns_without_blocking(): void
    {
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-09-01']);
        $result = $this->service()->transition((int) $cheque->id, 'deposited', ['bank_account_id' => 1], 1, self::ADMIN);

        $this->assertSame('deposited', $result['instrument']->status);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('plazo de presentación', $result['warnings'][0]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------------------------------------------------

    public function test_update_recomputes_deadlines_and_is_refused_once_the_instrument_moved(): void
    {
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-11-10', 'number' => 'ED-1']);
        $updated = $this->service()->update((int) $cheque->id, $this->payload(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, [
            'number' => 'ED-1', 'issue_date' => '2026-11-15', 'amount' => 900.00,
        ]), 1, self::ADMIN);

        $this->assertSame(900.0, (float) $updated->amount);
        $this->assertSame('2026-12-15', $updated->presentation_deadline->format('Y-m-d'));
        $this->assertSame('updated', TreasuryInstrumentEvent::where('instrument_id', $cheque->id)->orderByDesc('id')->first()->action);

        $this->service()->transition((int) $cheque->id, 'deposited', ['bank_account_id' => 1], 1, self::ADMIN);

        try {
            $this->service()->update((int) $cheque->id, $this->payload(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['number' => 'ED-1']), 1, self::ADMIN);
            $this->fail('Expected refusal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Source link, access
    // ---------------------------------------------------------------------------------------------------------

    public function test_source_link_requires_an_accepted_document_with_the_same_currency(): void
    {
        $docId = $this->insertDocument(['total' => 1500.00]);
        $instrument = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId]);
        $this->assertSame(\App\Models\Tenant\Document::class, $instrument->source_type);
        $this->assertSame($docId, (int) $instrument->source_id);

        $usdDoc = $this->insertDocument(['currency_type_id' => 'USD', 'exchange_rate_sale' => 3.75]);
        try {
            $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $usdDoc]);
            $this->fail('Expected currency refusal.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('moneda', implode(' ', $e->errors()['source_id']));
        }

        $voidedDoc = $this->insertDocument(['state_type_id' => '11']);
        $this->expectException(ValidationException::class);
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $voidedDoc]);
    }

    public function test_payable_types_link_to_purchases(): void
    {
        $purchaseId = $this->insertPurchase(['total' => 1500.00]);
        $bill = $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE, ['source_id' => $purchaseId]);

        $this->assertSame(\App\Models\Tenant\Purchase::class, $bill->source_type);
    }

    public function test_non_admin_is_confined_to_their_own_establishment_and_instruments(): void
    {
        $seller = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1];
        $mine = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, [], $seller, 2);
        $this->assertSame(1, (int) $mine->establishment_id);

        // Even when asking for another establishment the instrument is created in their own.
        $spoof = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['establishment_id' => 2], $seller, 2);
        $this->assertSame(1, (int) $spoof->establishment_id);

        $adminOwned = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED);
        try {
            $this->service()->transition((int) $adminOwned->id, 'deposited', ['bank_account_id' => 1], 2, $seller);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('permiso', $e->getMessage());
        }

        // Account of another establishment.
        $this->db->table('bank_accounts')->where('id', 2)->update(['establishment_id' => 2]);
        try {
            $this->service()->transition((int) $mine->id, 'deposited', ['bank_account_id' => 2], 2, $seller);
            $this->fail('Expected AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otra sede', $e->getMessage());
        }

        // Establishment <= 0 is denied everywhere.
        $nobody = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 0];
        foreach ([
            function () use ($nobody) {
                $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, [], $nobody, 2);
            },
            function () use ($nobody, $mine) {
                $this->service()->transition((int) $mine->id, 'deposited', ['bank_account_id' => 1], 2, $nobody);
            },
            function () use ($nobody, $mine) {
                $this->service()->history((int) $mine->id, $nobody);
            },
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected AuthorizationException for establishment 0.');
            } catch (AuthorizationException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(0, $this->service()->listRecords(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, $nobody)['meta']['total']);
        $this->assertSame(2, $this->service()->listRecords(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, $seller)['meta']['total']);
        $this->assertSame(3, $this->service()->listRecords(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, self::ADMIN)['meta']['total']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Alerts and listing
    // ---------------------------------------------------------------------------------------------------------

    public function test_alerts_cover_cheque_deadlines_bills_due_soon_overdue_and_protest_windows(): void
    {
        $today = '2026-11-23';

        // Received cheque, presentation deadline in 2 days (issued 28 days ago).
        $soon = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-10-26', 'number' => 'C-SOON']);
        // Issued cheque already expired (issued 40 days ago).
        $expired = $this->create(TreasuryInstrument::TYPE_CHEQUE_ISSUED, ['issue_date' => '2026-10-14', 'number' => 'C-OLD']);
        // Cheque far from its deadline.
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-11-20', 'number' => 'C-FINE']);
        // Bill due in 3 days.
        $dueSoon = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-10-01', 'due_date' => '2026-11-26', 'number' => 'L-SOON']);
        // Bill due far away.
        $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE, ['issue_date' => '2026-10-01', 'due_date' => '2027-02-01', 'number' => 'L-FAR']);
        // Overdue bill whose protest window closes in 2 days: due 2026-11-02 + 15 business days.
        $overdue = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-10-01', 'due_date' => '2026-11-02', 'number' => 'L-OVER']);
        // Overdue payable bill (counted as overdue, no protest alert: we are the debtor).
        $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE, ['issue_date' => '2026-09-01', 'due_date' => '2026-10-20', 'number' => 'L-PAY']);
        // Settled instruments never alert.
        $this->createAt(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, 'collected', ['issue_date' => '2026-10-14', 'number' => 'C-DONE']);

        $this->assertSame('2026-11-25', $soon->presentation_deadline->format('Y-m-d'));
        $this->assertSame('2026-11-23', $overdue->protest_deadline->format('Y-m-d'));

        $alerts = $this->service()->getAlerts(self::ADMIN, $today);

        $this->assertSame(1, $alerts['counts']['cheque_presentation_soon']);
        $this->assertSame(1, $alerts['counts']['cheque_presentation_expired']);
        $this->assertSame(1, $alerts['counts']['bill_due_soon']);
        $this->assertSame(2, $alerts['counts']['bill_overdue']);

        $byKind = [];
        foreach ($alerts['items'] as $item) {
            $byKind[$item['kind']][] = $item['number'];
        }
        $this->assertSame(['C-SOON'], $byKind['cheque_presentation_soon']);
        $this->assertSame(['C-OLD'], $byKind['cheque_presentation_expired']);
        $this->assertSame(['L-SOON'], $byKind['bill_due_soon']);
        // W4: an instrument is listed once. L-OVER sits in its protest window, so it appears under the more
        // specific group; the counts of every group stay complete.
        $this->assertSame(['L-PAY'], $byKind['bill_overdue']);
        $this->assertSame(['L-OVER'], $byKind['protest_deadline_soon']);

        // The protest windows are asserted against explicit as-of dates (deadline = business days after due).
        $protestDeadline = $overdue->protest_deadline->format('Y-m-d');
        $closing = $this->service()->getAlerts(self::ADMIN, date('Y-m-d', strtotime($protestDeadline . ' -2 days')));
        $this->assertSame(1, $closing['counts']['protest_deadline_soon']);
        $this->assertSame(0, $closing['counts']['protest_deadline_expired']);

        $after = $this->service()->getAlerts(self::ADMIN, date('Y-m-d', strtotime($protestDeadline . ' +1 day')));
        $this->assertSame(0, $after['counts']['protest_deadline_soon']);
        $this->assertGreaterThanOrEqual(1, $after['counts']['protest_deadline_expired']);

        // Settling the bill removes it from the alerts.
        $this->service()->transition((int) $dueSoon->id, 'voided', ['notes' => 'Anulada de prueba'], 1, self::ADMIN);
        $this->assertSame(0, $this->service()->getAlerts(self::ADMIN, $today)['counts']['bill_due_soon']);
    }

    public function test_alerts_are_scoped_for_non_admins(): void
    {
        $seller = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1];
        $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-10-01', 'due_date' => '2026-11-25', 'number' => 'ADM'], self::ADMIN, 1);
        $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-10-01', 'due_date' => '2026-11-25', 'number' => 'SEL'], $seller, 2);

        $this->assertSame(1, $this->service()->getAlerts($seller, '2026-11-23')['counts']['bill_due_soon']);
        $this->assertSame(2, $this->service()->getAlerts(self::ADMIN, '2026-11-23')['counts']['bill_due_soon']);
        $this->assertSame(0, $this->service()->getAlerts(['is_admin' => false, 'user_id' => 2, 'establishment_id' => 0], '2026-11-23')['total']);
    }

    public function test_list_exposes_labels_actions_totals_and_flags(): void
    {
        $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-09-01', 'due_date' => '2026-10-01', 'amount' => 800.00, 'number' => 'L-1']);
        $this->createAt(TreasuryInstrument::TYPE_BILL_RECEIVABLE, 'collected', ['number' => 'L-2']);

        $list = $this->service()->listRecords(TreasuryInstrument::TYPE_BILL_RECEIVABLE, array_merge(self::ADMIN, ['status' => 'all']));
        $this->assertSame(2, $list['meta']['total']);
        $this->assertSame([['currency_type_id' => 'PEN', 'count' => 1, 'amount' => 800.0]], $list['totals']);

        $row = collect($list['rows'])->firstWhere('number', 'L-1');
        $this->assertSame('Girada', $row['status_label']);
        $this->assertSame(['delivered', 'voided'], array_column($row['actions'], 'to'));
        $this->assertContains('overdue', $row['flags']);

        $open = $this->service()->listRecords(TreasuryInstrument::TYPE_BILL_RECEIVABLE, array_merge(self::ADMIN, ['status' => 'open']));
        $this->assertSame(1, $open['meta']['total']);

        $found = $this->service()->listRecords(TreasuryInstrument::TYPE_BILL_RECEIVABLE, array_merge(self::ADMIN, ['search' => 'L-2']));
        $this->assertSame(1, $found['meta']['total']);

        $history = $this->service()->history((int) $row['id'], self::ADMIN);
        $this->assertCount(1, $history['events']);
        $this->assertSame('Girada', $history['events'][0]['to_label']);
    }
}
