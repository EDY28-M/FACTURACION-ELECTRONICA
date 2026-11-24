<?php

namespace Tests\Unit\Treasury;

use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Services\CashFlowService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7: pending cheques and bills in the projected cash flow, and the no-double-count rule with linked documents.
 */
class InstrumentCashFlowTest extends TestCase
{
    use Phase4Harness;

    private const ADMIN = ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];
    private const AS_OF = '2026-11-20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();

        $this->db->statement('create table income (id integer primary key autoincrement, user_id integer, establishment_id integer, state_type_id text, customer text null, number text null, date_of_issue text, currency_type_id text, exchange_rate_sale numeric, total numeric)');
        $this->db->statement('create table income_payments (id integer primary key autoincrement, income_id integer, date_of_payment text, payment numeric, reference text null)');
        $this->db->statement('create table cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text, description text null, payment numeric)');
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function projected(array $scope = self::ADMIN): array
    {
        $service = new CashFlowService($this->makeBalanceService(), $this->makeReceivablePayableService());

        return $service->getProjectedCashFlow([
            'date_start' => self::AS_OF,
            'date_end' => '2026-12-31',
            'granularity' => 'month',
            'include_overdue' => true,
        ], $scope, self::AS_OF);
    }

    private function create(string $type, array $overrides = [], array $scope = self::ADMIN, int $userId = 1): TreasuryInstrument
    {
        $isInflow = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_BILL_RECEIVABLE], true);
        $isCheque = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_CHEQUE_ISSUED], true);

        return $this->makeInstrumentService()->create(array_merge([
            'type' => $type,
            'number' => 'N-' . random_int(100000, 999999),
            'amount' => 400.00,
            'currency_type_id' => 'PEN',
            'issue_date' => $isCheque ? '2026-11-10' : '2026-10-20',
            'due_date' => $isCheque ? '2026-11-25' : '2026-11-30',
            'is_deferred' => $isCheque,
            'bank_id' => 1,
            'bank_account_id' => $type === TreasuryInstrument::TYPE_CHEQUE_ISSUED ? 1 : null,
            'person_id' => $isInflow ? 1 : 10,
        ], $overrides), $userId, $scope);
    }

    private function move(TreasuryInstrument $instrument, string $to, array $payload = []): TreasuryInstrument
    {
        return $this->makeInstrumentService()->transition((int) $instrument->id, $to, array_merge([
            'bank_account_id' => 1,
            'notes' => 'Motivo de prueba',
        ], $payload), 1, self::ADMIN)['instrument'];
    }

    private function rowTotals(array $report, string $side): array
    {
        return array_column($report[$side . '_rows'], 'total', 'category');
    }

    private function documentWithFee(float $amount, string $date = '2026-11-25', array $overrides = []): int
    {
        $id = $this->insertDocument(array_merge(['date_of_issue' => '2026-11-01', 'total' => $amount], $overrides));
        $this->db->table('document_fee')->insert([
            'document_id' => $id,
            'date' => $date,
            'currency_type_id' => $overrides['currency_type_id'] ?? 'PEN',
            'amount' => $amount,
        ]);

        return $id;
    }

    public function test_pending_instruments_are_projected_by_direction_and_settled_ones_are_not(): void
    {
        // Deferred cheque received (due 25 Nov) and a bill receivable (due 30 Nov): inflows.
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED);
        $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['amount' => 600.00]);
        // Deferred cheque issued and bill payable: outflows.
        $this->create(TreasuryInstrument::TYPE_CHEQUE_ISSUED, ['amount' => 150.00]);
        $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE, ['amount' => 250.00]);

        // Never projected: voided, bounced, collected (real money already) and a protested RECEIVABLE bill.
        $voided = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['amount' => 11.00]);
        $this->move($voided, 'voided');
        $bounced = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['amount' => 22.00]);
        $this->move($this->move($bounced, 'deposited'), 'bounced');
        $collected = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['amount' => 33.00]);
        $this->move($this->move($collected, 'delivered'), 'collected');
        $protested = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['amount' => 44.00, 'issue_date' => '2026-08-01', 'due_date' => '2026-09-01']);
        $this->move($this->move($protested, 'delivered'), 'protested');

        $report = $this->projected();

        $inflows = $this->rowTotals($report, 'inflow');
        $outflows = $this->rowTotals($report, 'outflow');
        $this->assertSame(1000.0, $inflows['Cheques y letras por cobrar']);
        $this->assertSame(400.0, $outflows['Cheques y letras por pagar']);
        $this->assertSame(1000.0, $report['totals']['inflows']);
        $this->assertSame(400.0, $report['totals']['outflows']);
    }

    public function test_a_protested_payable_bill_is_still_owed_and_projected(): void
    {
        $bill = $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE, ['amount' => 300.00, 'issue_date' => '2026-08-01', 'due_date' => '2026-09-01']);
        $this->move($this->move($bill, 'delivered'), 'protested');

        $report = $this->projected();

        // Past due, so it lands in the overdue bucket.
        $this->assertSame(300.0, $this->rowTotals($report, 'outflow')['Cheques y letras por pagar']);
        $this->assertSame(300.0, $report['period_summary'][0]['outflows']);
        $this->assertSame('overdue', $report['period_summary'][0]['key']);
    }

    public function test_a_cheque_at_sight_is_expected_at_the_bank_now_not_overdue(): void
    {
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['is_deferred' => false, 'issue_date' => '2026-11-05', 'due_date' => null, 'amount' => 700.00]);

        $report = $this->projected();

        $this->assertSame(700.0, $report['totals']['inflows']);
        $this->assertSame(0.0, $report['period_summary'][0]['inflows']); // overdue bucket stays empty
        $this->assertSame(700.0, $report['period_summary'][1]['inflows']);
    }

    public function test_unlinked_instrument_and_document_are_both_projected(): void
    {
        $this->documentWithFee(1000.00);
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['amount' => 1000.00]);

        $this->assertSame(2000.0, $this->projected()['totals']['inflows']);
    }

    public function test_a_linked_receivable_instrument_replaces_the_document_installment_instead_of_adding_to_it(): void
    {
        $docId = $this->documentWithFee(1000.00);
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['amount' => 1000.00, 'source_id' => $docId]);

        $report = $this->projected();

        // Counted once: the cheque (on its own date), not the installment.
        $this->assertSame(1000.0, $report['totals']['inflows']);
        $inflows = $this->rowTotals($report, 'inflow');
        $this->assertSame(1000.0, $inflows['Cheques y letras por cobrar']);
        $this->assertArrayNotHasKey('Cobranzas de clientes (cuotas)', $inflows);
    }

    public function test_a_partial_linked_instrument_only_removes_its_own_amount_from_the_document(): void
    {
        $docId = $this->documentWithFee(1000.00);
        $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['amount' => 400.00, 'source_id' => $docId]);

        $report = $this->projected();
        $inflows = $this->rowTotals($report, 'inflow');

        $this->assertSame(600.0, $inflows['Cobranzas de clientes (cuotas)']);
        $this->assertSame(400.0, $inflows['Cheques y letras por cobrar']);
        $this->assertSame(1000.0, $report['totals']['inflows']);
    }

    public function test_the_cover_never_exceeds_what_is_pending_on_the_document(): void
    {
        $docId = $this->documentWithFee(300.00);
        // Two instruments of 300 each linked to a 300 document: the document disappears, instruments keep their amount.
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['amount' => 300.00, 'source_id' => $docId, 'number' => 'A']);
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['amount' => 300.00, 'source_id' => $docId, 'number' => 'B']);

        $report = $this->projected();

        $this->assertSame(600.0, $report['totals']['inflows']);
        $this->assertArrayNotHasKey('Cobranzas de clientes (cuotas)', $this->rowTotals($report, 'inflow'));
    }

    public function test_a_collected_linked_instrument_closes_the_document_through_its_real_payment_and_a_bounce_reopens_it(): void
    {
        // Legacy payment flow stand-ins (the collection registers a DocumentPayment, D4).
        $this->db->statement('create table payment_method_types (id text primary key, description text)');
        $this->db->table('payment_method_types')->insert(['id' => '04', 'description' => 'Transferencia']);
        foreach (['payment_method_type_id text null', 'has_card integer default 0', 'card_brand_id text null', 'change numeric null'] as $column) {
            $this->db->statement('alter table document_payments add column ' . $column);
        }

        $docId = $this->documentWithFee(1000.00);
        $cheque = $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['amount' => 1000.00, 'source_id' => $docId]);

        // Pending: the cheque covers the installment (counted once).
        $this->assertSame(1000.0, $this->projected()['totals']['inflows']);

        $cheque = $this->move($this->move($cheque, 'deposited'), 'collected');

        // The REAL payment on the document closed it: nothing is projected, and a collected instrument no longer
        // "covers" anything (it is not projected either).
        $this->assertSame(1, $this->db->table('document_payments')->where('document_id', $docId)->count());
        $this->assertSame(0, $this->db->table('treasury_movements')->where('source_type', TreasuryInstrument::class)->count());
        $this->assertSame(0.0, $this->projected()['totals']['inflows']);

        // Without the real payment the installment would come back at full value, not be hidden by the instrument.
        $this->db->table('document_payments')->where('document_id', $docId)->delete();
        $this->assertSame(1000.0, $this->projected()['totals']['inflows']);
        $this->assertSame(1000.0, $this->rowTotals($this->projected(), 'inflow')['Cobranzas de clientes (cuotas)']);
        $this->assertArrayNotHasKey('Cheques y letras por cobrar', $this->rowTotals($this->projected(), 'inflow'));

        // Bounce (the payment is already gone here): the receivable stays open and the cheque is not projected.
        $this->move($cheque, 'bounced');
        $report = $this->projected();
        $this->assertSame(1000.0, $report['totals']['inflows']);
        $this->assertSame(1000.0, $this->rowTotals($report, 'inflow')['Cobranzas de clientes (cuotas)']);
    }

    public function test_a_protested_receivable_bill_stops_covering_the_document(): void
    {
        $docId = $this->documentWithFee(900.00, '2026-11-28');
        $bill = $this->create(TreasuryInstrument::TYPE_BILL_RECEIVABLE, ['amount' => 900.00, 'source_id' => $docId, 'issue_date' => '2026-08-01', 'due_date' => '2026-11-10']);

        $this->assertSame(900.0, $this->projected()['totals']['inflows']);

        $this->move($this->move($bill, 'delivered'), 'protested');

        $report = $this->projected();
        $this->assertSame(900.0, $report['totals']['inflows']);
        $this->assertSame(900.0, $this->rowTotals($report, 'inflow')['Cobranzas de clientes (cuotas)']);
    }

    public function test_a_linked_payable_instrument_replaces_the_purchase_installment(): void
    {
        $purchaseId = $this->insertPurchase(['date_of_issue' => '2026-11-01', 'date_of_due' => '2026-12-02', 'total' => 800.00]);
        $this->create(TreasuryInstrument::TYPE_BILL_PAYABLE, ['amount' => 800.00, 'source_id' => $purchaseId, 'due_date' => '2026-12-02']);

        $report = $this->projected();

        $this->assertSame(800.0, $report['totals']['outflows']);
        $outflows = $this->rowTotals($report, 'outflow');
        $this->assertSame(800.0, $outflows['Cheques y letras por pagar']);
        $this->assertArrayNotHasKey('Pagos a proveedores', $outflows);
    }

    public function test_non_admin_projection_only_includes_their_own_instruments(): void
    {
        $seller = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1];
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['amount' => 500.00, 'number' => 'ADM'], self::ADMIN, 1);
        $this->create(TreasuryInstrument::TYPE_CHEQUE_RECEIVED, ['amount' => 70.00, 'number' => 'SEL'], $seller, 2);

        $this->assertSame(570.0, $this->projected(self::ADMIN)['totals']['inflows']);
        $this->assertSame(70.0, $this->projected($seller)['totals']['inflows']);
        // No establishment: fails closed.
        $this->assertSame(0.0, $this->projected(['is_admin' => false, 'user_id' => 2, 'establishment_id' => 0])['totals']['inflows']);
    }
}
