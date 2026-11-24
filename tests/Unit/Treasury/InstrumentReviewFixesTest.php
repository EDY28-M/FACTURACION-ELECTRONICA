<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Models\GlobalPayment;
use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Models\TreasuryInstrumentEvent;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\InstrumentService;
use Modules\Treasury\Support\ClosedReconciliationGuard;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 review fixes: a LINKED instrument settles through the legacy payment flow (D4), the audited
 * collected -> delivered correction, deadline refresh, bounced cheque re-presentation, alert caps, establishment
 * check, concept resolution by code and the protest period on a non-business maturity.
 */
class InstrumentReviewFixesTest extends TestCase
{
    use Phase4Harness;

    private const ADMIN = ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness(['2026-12-08']);
        $this->bootLegacyPaymentFlow();
    }

    protected function tearDown(): void
    {
        ClosedReconciliationGuard::flushCache();
        $this->tearDownHarness();
        parent::tearDown();
    }

    /**
     * The legacy payment models eager-load many relations and rely on observers; this creates empty stand-in tables
     * and registers the REAL observers so the tests exercise the same flow as DocumentPaymentController.
     */
    private function bootLegacyPaymentFlow(): void
    {
        foreach ([
            'payment_method_types (id text primary key, description text, has_card integer default 0, is_cash integer default 0)',
            'card_brands (id text primary key, description text)',
            'state_types (id text primary key, description text)',
            'groups (id integer primary key, description text)',
            'soap_types (id text primary key, description text)',
            'cat_document_types (id text primary key, description text)',
            'cat_currency_types (id text primary key, description text)',
            'document_items (id integer primary key autoincrement, document_id integer)',
            'purchase_items (id integer primary key autoincrement, purchase_id integer)',
            'payment_files (id integer primary key autoincrement, payment_id integer, payment_type text, filename text null)',
        ] as $table) {
            $this->db->statement('create table ' . $table);
        }
        $this->db->table('payment_method_types')->insert([
            ['id' => '01', 'description' => 'Efectivo'],
            ['id' => '04', 'description' => 'Transferencia'],
        ]);
        foreach (['payment_method_type_id text null', 'has_card integer default 0', 'card_brand_id text null', 'change numeric null'] as $column) {
            $this->db->statement('alter table document_payments add column ' . $column);
        }
        foreach (['payment_method_type_id text null', 'has_card integer default 0', 'card_brand_id text null'] as $column) {
            $this->db->statement('alter table purchase_payments add column ' . $column);
        }
        $this->db->statement('alter table documents add column created_at text null');
        $this->db->statement('alter table documents add column updated_at text null');
        $this->db->statement('alter table purchases add column created_at text null');
        $this->db->statement('alter table purchases add column updated_at text null');

        ClosedReconciliationGuard::flushCache();
        (new \App\Providers\DocumentPaymentProvider($this->app))->boot();
        (new \Modules\Finance\Providers\GlobalPaymentServiceProvider($this->app))->boot();
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
            'amount' => 1000.00,
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

    private function move(TreasuryInstrument $instrument, string $to, array $extra = [], array $scope = self::ADMIN): TreasuryInstrument
    {
        return $this->service()->transition((int) $instrument->id, $to, array_merge([
            'bank_account_id' => 1,
            'notes' => 'Motivo de prueba',
        ], $extra), 1, $scope)['instrument'];
    }

    /** Received cheque taken to "collected" (deposit first). */
    private function collectCheque(TreasuryInstrument $instrument): TreasuryInstrument
    {
        return $this->move($this->move($instrument, 'deposited'), 'collected');
    }

    private function balance(int $accountId = 1): float
    {
        // SQLite stores Eloquent `date` casts as 'Y-m-d 00:00:00' while the MySQL `date` column keeps 'Y-m-d': mimic it.
        foreach (['document_payments', 'purchase_payments'] as $table) {
            foreach ($this->db->table($table)->get(['id', 'date_of_payment']) as $row) {
                $this->db->table($table)->where('id', $row->id)->update(['date_of_payment' => substr((string) $row->date_of_payment, 0, 10)]);
            }
        }

        return (float) $this->makeBalanceService()->getBankAccountBalanceAsOf($accountId, date('Y-m-d'))['balance'];
    }

    private function refusal(callable $action, string $field = 'status'): string
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return implode(' ', $e->errors()[$field]);
        }
        $this->fail('Expected a ValidationException on ' . $field);
    }

    // ---------------------------------------------------------------------------------------------------------
    // C1: linked instruments settle through the legacy payment flow
    // ---------------------------------------------------------------------------------------------------------

    public function test_collecting_a_linked_receivable_cheque_registers_the_document_payment_and_no_movement(): void
    {
        $docId = $this->insertDocument(['total' => 1000.00]);
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId, 'number' => 'CH-77']);

        $cheque = $this->collectCheque($cheque);

        $payment = DocumentPayment::where('document_id', $docId)->get();
        $this->assertCount(1, $payment);
        $this->assertSame(1000.0, (float) $payment[0]->payment);
        $this->assertSame('04', (string) $payment[0]->payment_method_type_id);
        $this->assertSame('Cheque CH-77', $payment[0]->reference);

        $global = GlobalPayment::where('payment_type', DocumentPayment::class)->where('payment_id', $payment[0]->id)->get();
        $this->assertCount(1, $global);
        $this->assertSame(BankAccount::class, $global[0]->destination_type);
        $this->assertSame(1, (int) $global[0]->destination_id);
        $this->assertSame(1, (int) $global[0]->user_id);

        // The legacy observer closed the receivable; nothing else was booked in Treasury.
        $this->assertSame(1, (int) $this->db->table('documents')->where('id', $docId)->value('total_canceled'));
        $this->assertSame(0, TreasuryMovement::where('source_type', TreasuryInstrument::class)->count());
        $this->assertNull($cheque->movement_id);
        $this->assertSame(DocumentPayment::class, $cheque->payment_type);
        $this->assertSame((int) $payment[0]->id, (int) $cheque->payment_id);

        // The cash is counted ONCE (as the document payment on the bank account).
        $this->assertSame(6000.0, $this->balance(1));

        // A second instrument can no longer be linked to the settled document.
        $this->assertStringContainsString('cancelado', $this->refusal(function () use ($docId) {
            $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId, 'number' => 'CH-78']);
        }, 'source_id'));
    }

    public function test_collecting_a_linked_payable_bill_registers_the_purchase_payment(): void
    {
        $purchaseId = $this->insertPurchase(['total' => 800.00]);
        $bill = $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE, ['amount' => 800.00, 'source_id' => $purchaseId]);

        $bill = $this->move($this->move($bill, 'delivered'), 'collected', ['bank_account_id' => 2]);

        $payment = PurchasePayment::where('purchase_id', $purchaseId)->first();
        $this->assertNotNull($payment);
        $this->assertSame(800.0, (float) $payment->payment);
        $this->assertSame(1, (int) $this->db->table('purchases')->where('id', $purchaseId)->value('total_canceled'));
        $this->assertSame(2, (int) GlobalPayment::where('payment_type', PurchasePayment::class)->value('destination_id'));
        $this->assertSame(0, TreasuryMovement::count());
        $this->assertSame(PurchasePayment::class, $bill->payment_type);
        $this->assertSame(-800.0 + 100.0, $this->balance(2));
    }

    public function test_bouncing_a_linked_collected_instrument_deletes_the_payment_and_reopens_the_document(): void
    {
        $docId = $this->insertDocument(['total' => 1000.00]);
        $cheque = $this->collectCheque($this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId]));

        $bounced = $this->move($cheque, 'bounced', ['notes' => 'Sin fondos']);

        $this->assertSame('bounced', $bounced->status);
        $this->assertNull($bounced->payment_id);
        $this->assertNull($bounced->payment_type);
        $this->assertSame(0, DocumentPayment::count());
        $this->assertSame(0, GlobalPayment::count());
        $this->assertSame(0, (int) $this->db->table('documents')->where('id', $docId)->value('total_canceled'));
        $this->assertSame(5000.0, $this->balance(1));
    }

    public function test_a_closed_reconciliation_blocks_the_reversal_and_leaves_the_instrument_untouched(): void
    {
        $docId = $this->insertDocument(['total' => 1000.00]);
        $cheque = $this->collectCheque($this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId]));

        $globalPaymentId = (int) GlobalPayment::where('payment_id', $cheque->payment_id)->value('id');
        $statementId = (int) $this->db->table('bank_statements')->insertGetId([
            'bank_account_id' => 1, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'opening_balance' => 0, 'closing_balance' => 1000, 'status' => 'closed', 'imported_by' => 1,
        ]);
        $lineId = (int) $this->db->table('bank_statement_lines')->insertGetId([
            'bank_statement_id' => $statementId, 'line_no' => 1, 'date' => date('Y-m-d'), 'description' => 'Abono', 'amount' => 1000, 'status' => 'matched',
        ]);
        $this->db->table('bank_statement_line_matches')->insert([
            'bank_statement_line_id' => $lineId, 'matchable_type' => GlobalPayment::class, 'matchable_id' => $globalPaymentId,
            'amount' => 1000, 'item_date' => date('Y-m-d'), 'active_match_key' => GlobalPayment::class . ':' . $globalPaymentId,
        ]);

        foreach (['bounced', 'delivered'] as $to) {
            $message = $this->refusal(function () use ($cheque, $to) {
                $this->move($cheque, $to, ['notes' => 'Motivo de prueba']);
            }, 'message');
            $this->assertSame(ClosedReconciliationGuard::MESSAGE, $message);
        }

        $fresh = $cheque->fresh();
        $this->assertSame('collected', $fresh->status);
        $this->assertSame((int) $cheque->payment_id, (int) $fresh->payment_id);
        $this->assertSame(1, DocumentPayment::count());
        $this->assertSame(1, GlobalPayment::count());
        $this->assertSame(1, (int) $this->db->table('documents')->where('id', $docId)->value('total_canceled'));
    }

    public function test_link_time_validates_pending_balance_party_and_scope(): void
    {
        $docId = $this->insertDocument(['total' => 1000.00]);

        // amount > pending balance
        $this->assertStringContainsString('saldo pendiente', $this->refusal(function () use ($docId) {
            $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId, 'amount' => 1500.00]);
        }, 'amount'));

        // partially paid: the balance is what is left
        $this->db->table('document_payments')->insert(['document_id' => $docId, 'payment' => 400.00, 'date_of_payment' => '2026-11-01']);
        $this->assertStringContainsString('600.00', $this->refusal(function () use ($docId) {
            $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId, 'amount' => 700.00]);
        }, 'amount'));
        $ok = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId, 'amount' => 600.00]);
        $this->assertSame($docId, (int) $ok->source_id);

        // person that is not the customer of the document
        $this->db->table('persons')->insert(['id' => 2, 'type' => 'customers', 'name' => 'Otro Cliente', 'number' => '20100000002']);
        $this->assertStringContainsString('no coincide', $this->refusal(function () use ($docId) {
            $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId, 'amount' => 100.00, 'person_id' => 2, 'number' => 'X-2']);
        }, 'person_id'));

        // a seller cannot link the document of another user (same scoping as the picker)
        $foreign = $this->insertDocument(['user_id' => 1, 'total' => 300.00]);
        $seller = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1];
        $this->expectException(AuthorizationException::class);
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $foreign, 'amount' => 300.00, 'number' => 'X-3'], $seller, 2);
    }

    public function test_collection_rechecks_the_pending_balance_and_changes_nothing_when_it_shrank(): void
    {
        $docId = $this->insertDocument(['total' => 1000.00]);
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['source_id' => $docId]);
        $cheque = $this->move($cheque, 'deposited');

        // Someone registered a payment in the legacy screen in the meantime.
        $this->db->table('document_payments')->insert(['document_id' => $docId, 'payment' => 600.00, 'date_of_payment' => '2026-11-15']);

        $this->assertStringContainsString('saldo pendiente', $this->refusal(function () use ($cheque) {
            $this->move($cheque, 'collected');
        }, 'amount'));

        $this->assertSame('deposited', $cheque->fresh()->status);
        $this->assertSame(1, DocumentPayment::count());
        $this->assertSame(0, GlobalPayment::count());
    }

    public function test_unlinked_instruments_still_use_treasury_movements(): void
    {
        $cheque = $this->collectCheque($this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED));

        $this->assertNotNull($cheque->movement_id);
        $this->assertNull($cheque->payment_id);
        $this->assertSame(1, TreasuryMovement::where('source_type', TreasuryInstrument::class)->count());
        $this->assertSame(0, DocumentPayment::count());
        $this->assertSame(6000.0, $this->balance(1));
    }

    // ---------------------------------------------------------------------------------------------------------
    // C2: collected -> delivered correction
    // ---------------------------------------------------------------------------------------------------------

    public function test_revert_collected_to_delivered_voids_the_movement_for_every_type_and_requires_notes(): void
    {
        foreach (TreasuryInstrument::TYPES as $type) {
            $instrument = $this->create($type);
            $path = $type === TreasuryInstrument::TYPE_CHEQUE_RECEIVED
                ? ['deposited', 'collected']
                : ($type === TreasuryInstrument::TYPE_CHEQUE_ISSUED ? ['delivered', 'collected'] : ['delivered', 'collected']);
            foreach ($path as $step) {
                $instrument = $this->move($instrument, $step);
            }
            $this->assertSame('collected', $instrument->status);
            $movementId = (int) $instrument->movement_id;
            $balanceBefore = $this->balance(1);

            // notes are mandatory for the correction
            $this->refusal(function () use ($instrument) {
                $this->service()->transition((int) $instrument->id, 'delivered', ['notes' => ''], 1, self::ADMIN);
            }, 'notes');
            $this->assertSame('collected', $instrument->fresh()->status);

            $reverted = $this->move($instrument, 'delivered', ['notes' => 'Cobro registrado por error']);

            $this->assertSame('delivered', $reverted->status, $type);
            $this->assertNull($reverted->movement_id, $type);
            $this->assertSame('voided', TreasuryMovement::find($movementId)->state, $type);
            $this->assertNotEquals($balanceBefore, $this->balance(1), $type);

            $event = TreasuryInstrumentEvent::where('instrument_id', $instrument->id)->orderByDesc('id')->first();
            $this->assertSame('collected', $event->from_status);
            $this->assertSame('delivered', $event->to_status);
            $this->assertSame('Cobro registrado por error', $event->notes);
            $this->assertSame($movementId, (int) $event->movement_id);
        }

        $this->assertSame(5000.0, $this->balance(1));
    }

    public function test_revert_of_a_linked_instrument_deletes_the_payment_and_it_can_be_collected_again(): void
    {
        $docId = $this->insertDocument(['total' => 1000.00]);
        $bill = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['source_id' => $docId]);
        $bill = $this->move($this->move($bill, 'delivered'), 'collected');
        $this->assertSame(1, DocumentPayment::count());

        $reverted = $this->move($bill, 'delivered', ['notes' => 'Error de digitacion']);

        $this->assertSame('delivered', $reverted->status);
        $this->assertNull($reverted->payment_id);
        $this->assertNull($reverted->bank_account_id);
        $this->assertSame(0, DocumentPayment::count());
        $this->assertSame(0, GlobalPayment::count());
        $this->assertSame(0, (int) $this->db->table('documents')->where('id', $docId)->value('total_canceled'));

        // collected again, this time on another account
        $again = $this->move($reverted, 'collected', ['bank_account_id' => 2]);
        $this->assertSame(1, DocumentPayment::count());
        $this->assertSame(2, (int) GlobalPayment::value('destination_id'));
        $this->assertSame('collected', $again->status);
    }

    public function test_the_revert_action_is_exposed_with_its_label_and_notes(): void
    {
        $cheque = $this->collectCheque($this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED));
        $actions = collect($this->service()->present($cheque)['actions'])->keyBy('to');

        $this->assertSame('Revertir cobro/pago', $actions['delivered']['label']);
        $this->assertTrue($actions['delivered']['needs_notes']);
        $this->assertTrue($actions['delivered']['destructive']);
        $this->assertSame('Registrar rechazo', $actions['bounced']['label']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // W1: protest deadline refresh
    // ---------------------------------------------------------------------------------------------------------

    public function test_open_bill_deadlines_follow_the_holiday_list_after_it_changes(): void
    {
        // Created with NO holiday data: the deadline is an estimate.
        $bill = $this->service([])->create($this->payload(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-11-01', 'due_date' => '2026-11-27']), 1, self::ADMIN);
        $this->assertTrue((bool) $bill->deadline_is_estimate);
        $stored = $bill->protest_deadline->format('Y-m-d');

        // Later a holiday inside the window is loaded (2026-12-08 is in the list of this service).
        $updated = $this->service(['2026-12-08'])->refreshOpenBillDeadlines();
        $this->assertSame(1, $updated);

        $fresh = $bill->fresh();
        $this->assertNotSame($stored, $fresh->protest_deadline->format('Y-m-d'));
        $this->assertFalse((bool) $fresh->deadline_is_estimate);

        // Idempotent
        $this->assertSame(0, $this->service(['2026-12-08'])->refreshOpenBillDeadlines());

        // listRecords and the alerts recompute on their own
        $this->db->table('treasury_instruments')->where('id', $bill->id)->update(['protest_deadline' => '2030-01-01', 'deadline_is_estimate' => 1]);
        $row = $this->service(['2026-12-08'])->listRecords(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['is_admin' => true, 'user_id' => 1])['rows'][0];
        $this->assertSame($fresh->protest_deadline->format('Y-m-d'), $row['protest_deadline']);
        $this->assertFalse($row['deadline_is_estimate']);

        $this->db->table('treasury_instruments')->where('id', $bill->id)->update(['protest_deadline' => '2030-01-01']);
        $this->service(['2026-12-08'])->getAlerts(self::ADMIN, '2026-11-23');
        $this->assertSame($fresh->protest_deadline->format('Y-m-d'), $bill->fresh()->protest_deadline->format('Y-m-d'));
    }

    public function test_settled_bills_are_not_refreshed(): void
    {
        $bill = $this->service([])->create($this->payload(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-11-01', 'due_date' => '2026-11-27']), 1, self::ADMIN);
        $this->db->table('treasury_instruments')->where('id', $bill->id)->update(['status' => 'voided']);

        $this->assertSame(0, $this->service(['2026-12-08'])->refreshOpenBillDeadlines());
    }

    // ---------------------------------------------------------------------------------------------------------
    // W2: bounced cheque re-presentation
    // ---------------------------------------------------------------------------------------------------------

    public function test_a_bounced_received_cheque_can_be_presented_again_within_its_period_and_keeps_its_number_reserved(): void
    {
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-11-10', 'number' => 'REPR-1']);
        $key = $cheque->active_key;
        $this->assertNotNull($key);

        $cheque = $this->move($this->move($cheque, 'deposited'), 'bounced', ['notes' => 'Sin fondos']);
        $this->assertSame('bounced', $cheque->status);
        $this->assertSame($key, $cheque->active_key);

        // The number is still reserved while it can be presented again.
        $this->assertStringContainsString('Ya existe', $this->refusal(function () {
            $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-11-10', 'number' => 'REPR-1']);
        }, 'number'));

        $again = $this->move($cheque, 'deposited', ['bank_account_id' => 2]);
        $this->assertSame('deposited', $again->status);
        $this->assertSame($key, $again->active_key);
        $this->assertSame(2, (int) $again->bank_account_id);

        $collected = $this->move($again, 'collected', ['bank_account_id' => 2]);
        $this->assertSame('collected', $collected->status);
    }

    public function test_a_bounced_cheque_cannot_be_presented_after_its_legal_deadline(): void
    {
        // presentation deadline = 2026-11-10 + 30 days = 2026-12-10; the test forces it into the past.
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-11-10']);
        $cheque = $this->move($this->move($cheque, 'deposited'), 'bounced', ['notes' => 'Sin fondos']);
        $this->db->table('treasury_instruments')->where('id', $cheque->id)->update(['presentation_deadline' => '2026-11-12']);

        $message = $this->refusal(function () use ($cheque) {
            $this->move($cheque, 'deposited');
        }, 'date');
        $this->assertStringContainsString('plazo de presentación', $message);
        $this->assertSame('bounced', $cheque->fresh()->status);

        // Other types keep bounced as a terminal state.
        $issued = $this->create(TreasuryInstrument::TYPE_CHEQUE_ISSUED);
        $issued = $this->move($this->move($issued, 'delivered'), 'bounced');
        $this->assertSame([], InstrumentService::allowedTransitionTargets($issued->type, 'bounced'));
    }

    // ---------------------------------------------------------------------------------------------------------
    // W4: alerts
    // ---------------------------------------------------------------------------------------------------------

    public function test_alert_items_are_capped_per_group_without_starving_later_groups_and_deduplicated(): void
    {
        config(['treasury.instruments.alert_max_items' => 2]);

        // 4 expired cheques (group 1 of the old global cap) ...
        foreach (range(1, 4) as $i) {
            $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['issue_date' => '2026-09-0' . $i, 'number' => 'OLD-' . $i]);
        }
        // ... and 4 overdue payable bills (a later group): must still appear.
        foreach (range(1, 4) as $i) {
            $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE, ['issue_date' => '2026-09-01', 'due_date' => '2026-10-0' . $i, 'number' => 'PAY-' . $i]);
        }
        // One receivable bill that is overdue AND inside its protest window: counted in both groups, listed once.
        $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-10-01', 'due_date' => '2026-11-02', 'number' => 'REC-1']);

        $alerts = $this->service()->getAlerts(self::ADMIN, '2026-11-23');

        $this->assertSame(4, $alerts['counts']['cheque_presentation_expired']);
        $this->assertSame(5, $alerts['counts']['bill_overdue']);

        $perKind = [];
        $ids = [];
        foreach ($alerts['items'] as $item) {
            $perKind[$item['kind']] = ($perKind[$item['kind']] ?? 0) + 1;
            $ids[] = $item['id'];
        }
        $this->assertSame($ids, array_values(array_unique($ids)), 'an instrument is listed once');
        $this->assertSame(2, $perKind['cheque_presentation_expired']);
        $this->assertSame(2, $perKind['bill_overdue']);
        $this->assertSame(1, $perKind['protest_deadline_soon']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // W5 / S1 / S2 / S3
    // ---------------------------------------------------------------------------------------------------------

    public function test_an_account_of_another_establishment_is_refused_also_for_admins(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 5, 'bank_id' => 1, 'description' => 'Sucursal PEN', 'number' => '191-005', 'currency_type_id' => 'PEN', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2,
        ]);

        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED);
        $this->assertStringContainsString('otra sede', $this->refusal(function () use ($cheque) {
            $this->move($cheque, 'deposited', ['bank_account_id' => 5]);
        }, 'bank_account_id'));

        $bill = $this->move($this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE), 'delivered');
        $this->assertStringContainsString('otra sede', $this->refusal(function () use ($bill) {
            $this->move($bill, 'collected', ['bank_account_id' => 5]);
        }, 'bank_account_id'));

        $this->assertSame('delivered', $cheque->fresh()->status);
        $this->assertSame(0, TreasuryMovement::count());
    }

    public function test_instrument_concepts_are_resolved_by_code_and_fail_loudly_when_missing(): void
    {
        $income = $this->db->table('treasury_concepts')->where('code', 'instrument_income')->first();
        $expense = $this->db->table('treasury_concepts')->where('code', 'instrument_expense')->first();
        $this->assertNotNull($income);
        $this->assertNotNull($expense);

        // Renaming the concept does not break the link.
        $this->db->table('treasury_concepts')->where('id', $income->id)->update(['description' => 'Cobros varios renombrado']);
        $cheque = $this->collectCheque($this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED));
        $this->assertSame((int) $income->id, (int) TreasuryMovement::find($cheque->movement_id)->treasury_concept_id);

        // A missing / inactive concept is an error, never a silent null.
        $this->db->table('treasury_concepts')->where('id', $expense->id)->update(['active' => 0]);
        $issued = $this->move($this->create(TreasuryInstrument::TYPE_CHEQUE_ISSUED), 'delivered');
        $this->assertStringContainsString('instrument_expense', $this->refusal(function () use ($issued) {
            $this->move($issued, 'collected');
        }, 'concept'));
        $this->assertSame('delivered', $issued->fresh()->status);
        $this->assertSame(1, TreasuryMovement::count());
    }

    public function test_protest_period_of_a_bill_due_on_a_non_business_day_counts_from_the_next_business_day(): void
    {
        // 2026-10-03 is a Saturday: the maturity is carried to Monday 2026-10-05 and 15 business days run from there.
        $saturday = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['due_date' => '2026-10-03', 'number' => 'SAT']);
        $monday = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['due_date' => '2026-10-05', 'number' => 'MON']);

        $this->assertSame('2026-10-26', $monday->protest_deadline->format('Y-m-d'));
        $this->assertSame('2026-10-26', $saturday->protest_deadline->format('Y-m-d'));

        // A holiday behaves like a weekend (2026-12-08 is a Tuesday): counted from Wednesday 2026-12-09.
        $holiday = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-11-01', 'due_date' => '2026-12-08', 'number' => 'HOL']);
        $wednesday = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['issue_date' => '2026-11-01', 'due_date' => '2026-12-09', 'number' => 'WED']);
        $this->assertSame($wednesday->protest_deadline->format('Y-m-d'), $holiday->protest_deadline->format('Y-m-d'));
    }

    public function test_collecting_a_deferred_cheque_before_its_payment_date_warns_but_does_not_block(): void
    {
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, [
            'issue_date' => '2026-11-20', 'is_deferred' => true, 'due_date' => '2026-12-10',
        ]);
        $this->assertTrue(collect($this->service()->present($cheque->load('bank'))['actions'])->contains(function ($a) {
            return $a['to'] === 'deposited' && $a['warn_if_early'] === false;
        }));

        $deposited = $this->move($cheque, 'deposited');
        $actions = collect($this->service()->present($deposited)['actions'])->keyBy('to');
        $this->assertTrue($actions['collected']['warn_if_early']);

        $result = $this->service()->transition((int) $deposited->id, 'collected', ['bank_account_id' => 1, 'date' => '2026-11-23'], 1, self::ADMIN);
        $this->assertSame('collected', $result['instrument']->status);
        $this->assertStringContainsString('2026-12-10', implode(' ', $result['warnings']));
    }
}
