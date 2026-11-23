<?php

namespace Tests\Unit\Treasury;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\TreasuryWithholding;
use Modules\Treasury\Services\TaxRateRepository;
use PHPUnit\Framework\TestCase;

class RetentionPerceptionServiceTest extends TestCase
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

    private function setAgentFlags(bool $retention, bool $perception): void
    {
        $this->db->table('companies')->update([
            'is_retention_agent' => $retention ? 1 : 0,
            'is_perception_agent' => $perception ? 1 : 0,
        ]);
    }

    private function insertPurchasePayment(int $purchaseId, float $amount, string $date): int
    {
        return (int) $this->db->table('purchase_payments')->insertGetId([
            'purchase_id' => $purchaseId,
            'payment' => $amount,
            'date_of_payment' => $date,
        ]);
    }

    private function insertDocumentPayment(int $documentId, float $amount, string $date): int
    {
        return (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $documentId,
            'payment' => $amount,
            'date_of_payment' => $date,
        ]);
    }

    public function test_tax_rates_are_versioned_by_date(): void
    {
        $repo = new TaxRateRepository();

        $v1 = $repo->getRetentionRule('2026-12-31');
        $this->assertSame(0.03, $v1['rate']);
        $this->assertSame(700.0, $v1['min_operation']);

        $v2 = $repo->getRetentionRule('2027-01-01');
        $this->assertSame(0.04, $v2['rate']);
        $this->assertSame(1000.0, $v2['min_operation']);

        $this->assertNull($repo->getRetentionRule('2010-01-01'));
        $this->assertSame(0.02, $repo->getPerceptionRule('general', '2026-11-23')['rate']);
        $this->assertNull($repo->getPerceptionRule('unknown', '2026-11-23'));
        $this->assertSame(0.12, $repo->getDetractionRule('037', '2026-11-23')['rate']);
        $this->assertSame(['general' => 0.02, 'fuel' => 0.01, 'agent' => 0.005], $repo->listPerceptionRates('2026-11-23'));
    }

    public function test_retention_threshold_is_strict_and_uses_the_versioned_rule(): void
    {
        $service = $this->makeRetentionService();

        // Operation of exactly S/ 700 is NOT above the threshold.
        $atThreshold = $service->computeRetention(700.00, 700.00, 'PEN', null, '2026-11-23');
        $this->assertFalse($atThreshold['applicable']);
        $this->assertSame(0.0, $atThreshold['retention_amount']);

        $above = $service->computeRetention(1000.00, 1000.00, 'PEN', null, '2026-11-23');
        $this->assertTrue($above['applicable']);
        $this->assertSame(30.00, $above['retention_amount']);
        $this->assertSame(970.00, $above['net_payment']);

        // A foreign-currency operation is converted to PEN for the threshold only.
        $usd = $service->computeRetention(300.00, 300.00, 'USD', 3.75, '2026-11-23');
        $this->assertTrue($usd['applicable']);
        $this->assertSame(9.00, $usd['retention_amount']);
        $this->assertSame(1125.00, $usd['operation_amount_pen']);

        $noRate = $service->computeRetention(300.00, 300.00, 'USD', null, '2026-11-23');
        $this->assertFalse($noRate['applicable']);

        // Versioned: from 2027-01-01 the fixture rule is 4% over S/ 1,000.
        $this->assertFalse($service->computeRetention(900.00, 900.00, 'PEN', null, '2027-01-15')['applicable']);
        $this->assertSame(48.00, $service->computeRetention(1200.00, 1200.00, 'PEN', null, '2027-01-15')['retention_amount']);
    }

    public function test_partial_payments_are_retained_individually_when_the_operation_exceeds_the_threshold(): void
    {
        $this->setAgentFlags(true, false);
        $big = $this->insertPurchase(['number' => '500', 'total' => 1000.00]);
        $small = $this->insertPurchase(['number' => '501', 'total' => 600.00]);
        $p1 = $this->insertPurchasePayment($big, 400.00, '2026-11-02');
        $p2 = $this->insertPurchasePayment($big, 600.00, '2026-11-10');
        $this->insertPurchasePayment($small, 600.00, '2026-11-02');

        $service = $this->makeRetentionService();
        $offers = $service->getRetentionOffers($big);

        $this->assertTrue($offers['enabled']);
        $this->assertSame('/retentions/create', $offers['emit_url']);
        $this->assertCount(2, $offers['lines']);
        $this->assertSame(12.00, $offers['lines'][0]['retention_amount']);
        $this->assertSame(388.00, $offers['lines'][0]['net_payment']);
        $this->assertSame(18.00, $offers['lines'][1]['retention_amount']);
        $this->assertSame([$p1, $p2], array_column($offers['lines'], 'payment_id'));

        $smallOffers = $service->getRetentionOffers($small);
        $this->assertFalse($smallOffers['lines'][0]['applicable']);
        $this->assertStringContainsString('monto mínimo', $smallOffers['lines'][0]['reason']);
    }

    public function test_no_emission_options_without_agent_flags(): void
    {
        $this->setAgentFlags(false, false);
        $purchase = $this->insertPurchase(['total' => 1000.00]);
        $paymentId = $this->insertPurchasePayment($purchase, 1000.00, '2026-11-02');
        $document = $this->insertDocument(['total' => 1000.00]);
        $documentPaymentId = $this->insertDocumentPayment($document, 1000.00, '2026-11-02');
        $service = $this->makeRetentionService();

        $retention = $service->getRetentionOffers($purchase);
        $this->assertFalse($retention['enabled']);
        $this->assertNull($retention['emit_url']);
        $this->assertSame([], $retention['lines']);

        $perception = $service->getPerceptionOffers($document);
        $this->assertFalse($perception['enabled']);
        $this->assertNull($perception['emit_url']);
        $this->assertSame([], $perception['lines']);

        foreach ([
            function () use ($service, $paymentId) {
                $service->linkIssuedRetention($paymentId, ['series' => 'R001', 'number' => '1'], 1);
            },
            function () use ($service, $documentPaymentId) {
                $service->linkIssuedPerception($documentPaymentId, ['series' => 'P001', 'number' => '1'], 1);
            },
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected ValidationException without agent flags.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('series', $e->errors());
            }
        }
    }

    public function test_link_issued_retention_tracks_the_amount_as_payable_to_sunat(): void
    {
        $this->setAgentFlags(true, false);
        $purchase = $this->insertPurchase(['number' => '600', 'total' => 1000.00]);
        $paymentId = $this->insertPurchasePayment($purchase, 400.00, '2026-11-02');
        $service = $this->makeRetentionService();

        // Unknown certificate.
        try {
            $service->linkIssuedRetention($paymentId, ['series' => 'R001', 'number' => '9'], 1);
            $this->fail('Expected ValidationException for an unknown CRE.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('number', $e->errors());
        }

        // CRE with a wrong amount.
        $wrong = $this->db->table('retentions')->insertGetId([
            'series' => 'R001', 'number' => '1', 'state_type_id' => '05', 'date_of_issue' => '2026-11-02',
            'total_retention' => 20.00, 'supplier' => json_encode(['number' => '20200000010']),
        ]);
        try {
            $service->linkIssuedRetention($paymentId, ['series' => 'R001', 'number' => '1'], 1);
            $this->fail('Expected ValidationException for an amount mismatch.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no coincide', $e->errors()['number'][0]);
        }
        $this->db->table('retentions')->where('id', $wrong)->delete();

        // CRE of another supplier is refused.
        $this->db->table('retentions')->insert([
            'series' => 'R001', 'number' => '2', 'state_type_id' => '05', 'date_of_issue' => '2026-11-02',
            'total_retention' => 12.00, 'supplier' => json_encode(['number' => '20999999999']),
        ]);
        try {
            $service->linkIssuedRetention($paymentId, ['series' => 'R001', 'number' => '2'], 1);
            $this->fail('Expected ValidationException for a different supplier.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('number', $e->errors());
        }

        // Valid CRE.
        $this->db->table('retentions')->insert([
            'series' => 'R001', 'number' => '3', 'state_type_id' => '05', 'date_of_issue' => '2026-11-02',
            'total_retention' => 12.00, 'supplier' => json_encode(['number' => '20200000010']),
        ]);
        $row = $service->linkIssuedRetention($paymentId, ['series' => 'R001', 'number' => '3'], 1);

        $this->assertSame(TreasuryWithholding::KIND_RETENTION_EMITTED, $row->kind);
        $this->assertSame(12.00, $row->amount);
        $this->assertSame(400.00, $row->base_amount);
        $this->assertTrue($row->payable_to_sunat);

        // The same payment cannot be linked twice.
        try {
            $service->linkIssuedRetention($paymentId, ['series' => 'R001', 'number' => '3'], 1);
            $this->fail('Expected ValidationException for a duplicated link.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment', $e->errors());
        }

        $offers = $service->getRetentionOffers($purchase);
        $this->assertTrue($offers['lines'][0]['linked']);
        $this->assertSame('R001-3', $offers['lines'][0]['certificate']);

        $listing = $service->listWithholdings([]);
        $this->assertSame(12.00, $listing['sunat_payable']['total_pen']);
        $this->assertSame(12.00, $listing['sunat_payable']['by_period']['2026-11']);
    }

    public function test_perception_offers_use_versioned_rates_and_exclude_detraction_operations(): void
    {
        $this->setAgentFlags(false, true);
        $document = $this->insertDocument(['number' => '700', 'total' => 1000.00]);
        $this->insertDocumentPayment($document, 500.00, '2026-11-02');
        $withDetraction = $this->insertDocument(['number' => '701', 'total' => 1000.00, 'detraction' => $this->detractionJson(120.00)]);
        $this->insertDocumentPayment($withDetraction, 880.00, '2026-11-02');
        $service = $this->makeRetentionService();

        $general = $service->getPerceptionOffers($document, 'general');
        $this->assertTrue($general['enabled']);
        $this->assertSame('/perceptions/create', $general['emit_url']);
        $this->assertSame(10.00, $general['lines'][0]['perception_amount']);
        $this->assertSame(510.00, $general['lines'][0]['total_to_collect']);

        $fuel = $service->getPerceptionOffers($document, 'fuel');
        $this->assertSame(5.00, $fuel['lines'][0]['perception_amount']);

        $excluded = $service->getPerceptionOffers($withDetraction, 'general');
        $this->assertSame([], $excluded['lines']);
        $this->assertStringContainsString('detracción', $excluded['reason']);

        $unknown = $service->computePerception(100.00, 'unknown', '2026-11-23');
        $this->assertFalse($unknown['applicable']);
    }

    public function test_link_issued_perception_validates_amount_and_records_payable(): void
    {
        $this->setAgentFlags(false, true);
        $document = $this->insertDocument(['number' => '710', 'total' => 1000.00]);
        $paymentId = $this->insertDocumentPayment($document, 500.00, '2026-11-02');
        $service = $this->makeRetentionService();

        $this->db->table('perceptions')->insert([
            'series' => 'P001', 'number' => '1', 'state_type_id' => '05', 'date_of_issue' => '2026-11-02',
            'total_perception' => 10.00, 'customer' => json_encode(['number' => '20100000001']),
        ]);

        $row = $service->linkIssuedPerception($paymentId, ['series' => 'P001', 'number' => '1', 'rate_code' => 'general'], 1);

        $this->assertSame(TreasuryWithholding::KIND_PERCEPTION_EMITTED, $row->kind);
        $this->assertSame('40', $row->certificate_type);
        $this->assertSame(10.00, $row->amount);
        $this->assertTrue($row->payable_to_sunat);
    }

    public function test_received_retention_reduces_net_receivable_and_validates_inputs_and_ownership(): void
    {
        $document = $this->insertDocument(['number' => '800', 'total' => 1000.00]);
        $service = $this->makeRetentionService();

        $row = $service->registerReceivedRetention($document, [
            'series' => 'R001', 'number' => '55', 'date' => '2026-11-05', 'amount' => 30.00,
        ], 1);
        $this->assertSame(TreasuryWithholding::KIND_RETENTION_RECEIVED, $row->kind);
        $this->assertFalse($row->payable_to_sunat);
        $this->assertSame(0.03, $row->rate);

        $receivable = collect($this->makeReceivablePayableService()->getReceivables([])['rows'])->firstWhere('id', $document);
        $this->assertSame(1000.00, $receivable['balance']);
        $this->assertSame(30.00, $receivable['retention_registered_amount']);
        $this->assertSame(970.00, $receivable['net_receivable_amount']);

        // Duplicate certificate on the same invoice.
        try {
            $service->registerReceivedRetention($document, ['series' => 'R001', 'number' => '55', 'date' => '2026-11-05', 'amount' => 5.00], 1);
            $this->fail('Expected ValidationException for a duplicated CRE.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('number', $e->errors());
        }

        // Cannot exceed the invoice total.
        try {
            $service->registerReceivedRetention($document, ['series' => 'R001', 'number' => '56', 'date' => '2026-11-05', 'amount' => 980.00], 1);
            $this->fail('Expected ValidationException when exceeding the total.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        // Date before the invoice issue date, future date and bad amount are refused.
        foreach ([
            ['date' => '2026-10-01', 'amount' => 5, 'field' => 'date'],
            ['date' => '2999-01-01', 'amount' => 5, 'field' => 'date'],
            ['date' => '2026-11-05', 'amount' => -1, 'field' => 'amount'],
        ] as $case) {
            try {
                $service->registerReceivedRetention($document, [
                    'series' => 'R002', 'number' => '1', 'date' => $case['date'], 'amount' => $case['amount'],
                ], 1);
                $this->fail('Expected ValidationException for ' . $case['field']);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($case['field'], $e->errors());
            }
        }

        // Non-admin cannot register against another user's invoice.
        $this->expectException(AuthorizationException::class);
        $service->registerReceivedRetention($document, [
            'series' => 'R003', 'number' => '1', 'date' => '2026-11-05', 'amount' => 5.00,
        ], 2, ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1]);
    }

    public function test_declared_and_registered_retention_are_not_double_counted(): void
    {
        // The invoice itself declares a 30.00 retention (discount code 62) and the CRE confirms the same 30.00.
        $document = $this->insertDocument([
            'number' => '810',
            'total' => 1000.00,
            'discounts' => json_encode([['discount_type_id' => '62', 'amount' => 30.00]]),
        ]);
        $this->makeRetentionService()->registerReceivedRetention($document, [
            'series' => 'R001', 'number' => '60', 'date' => '2026-11-05', 'amount' => 30.00,
        ], 1);

        $row = collect($this->makeReceivablePayableService()->getReceivables([])['rows'])->firstWhere('id', $document);

        $this->assertSame(970.00, $row['net_receivable_amount']);
    }

    public function test_voiding_and_settling_withholdings(): void
    {
        $this->setAgentFlags(true, false);
        $purchase = $this->insertPurchase(['number' => '900', 'total' => 1000.00]);
        $paymentId = $this->insertPurchasePayment($purchase, 400.00, '2026-11-02');
        $this->db->table('retentions')->insert([
            'series' => 'R001', 'number' => '70', 'state_type_id' => '05', 'date_of_issue' => '2026-11-02',
            'total_retention' => 12.00, 'supplier' => json_encode(['number' => '20200000010']),
        ]);
        $service = $this->makeRetentionService();
        $row = $service->linkIssuedRetention($paymentId, ['series' => 'R001', 'number' => '70'], 1);

        // A tax payment smaller than the amount cannot settle it.
        $small = $this->makeMovementService()->createManual([
            'establishment_id' => 1, 'date' => '2026-11-06', 'kind' => 'tax_payment',
            'destination_type' => 'bank_account', 'destination_id' => 1, 'currency_type_id' => 'PEN',
            'amount' => 5, 'treasury_concept_id' => 1, 'description' => 'Pago parcial',
        ], 1)['movement'];
        try {
            $service->settleWithTaxPayment([$row->id], $small->id);
            $this->fail('Expected ValidationException for an insufficient tax payment.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('movement_id', $e->errors());
        }

        $tax = $this->makeMovementService()->createManual([
            'establishment_id' => 1, 'date' => '2026-11-06', 'kind' => 'tax_payment',
            'destination_type' => 'bank_account', 'destination_id' => 1, 'currency_type_id' => 'PEN',
            'amount' => 12, 'treasury_concept_id' => 1, 'description' => 'Form. 626',
        ], 1)['movement'];
        $this->assertSame(1, $service->settleWithTaxPayment([$row->id], $tax->id));
        $this->assertSame(0.0, $service->listWithholdings([])['sunat_payable']['total_pen']);

        // A settled record cannot be voided.
        try {
            $service->voidWithholding($row->id, 'Error', 1);
            $this->fail('Expected ValidationException when voiding a settled record.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('withholding', $e->errors());
        }

        // An unsettled one can be voided and then relinked.
        $this->db->table('purchase_payments')->insert(['purchase_id' => $purchase, 'payment' => 100.00, 'date_of_payment' => '2026-11-08']);
        $this->db->table('retentions')->insert([
            'series' => 'R001', 'number' => '71', 'state_type_id' => '05', 'date_of_issue' => '2026-11-08',
            'total_retention' => 3.00, 'supplier' => json_encode(['number' => '20200000010']),
        ]);
        $second = $service->linkIssuedRetention(2, ['series' => 'R001', 'number' => '71'], 1);
        $voided = $service->voidWithholding($second->id, 'Se emitió por error', 1);
        $this->assertSame(TreasuryWithholding::STATE_VOIDED, $voided->state);
        $this->assertSame(0.0, $service->listWithholdings([])['sunat_payable']['total_pen']);
        $this->assertNotNull($service->linkIssuedRetention(2, ['series' => 'R001', 'number' => '71'], 1));
    }

    // ---- Fix round: W1 / W2 / W3 / W5 / W8 ------------------------------------------------------------

    private function makeTaxPayment(float $amount): \Modules\Treasury\Models\TreasuryMovement
    {
        return $this->makeMovementService()->createManual([
            'establishment_id' => 1, 'date' => '2026-11-06', 'kind' => 'tax_payment',
            'destination_type' => 'bank_account', 'destination_id' => 1, 'currency_type_id' => 'PEN',
            'amount' => $amount, 'treasury_concept_id' => 1, 'description' => 'Form. 626',
        ], 1)['movement'];
    }

    private function makePayableWithholding(float $amountPen, array $overrides = []): TreasuryWithholding
    {
        return TreasuryWithholding::create(array_merge([
            'kind' => TreasuryWithholding::KIND_RETENTION_EMITTED,
            'certificate_type' => '20',
            'series' => 'R001',
            'number' => (string) random_int(1000, 999999),
            'date' => '2026-11-02',
            'establishment_id' => 1,
            'user_id' => 1,
            'source_type' => \App\Models\Tenant\PurchasePayment::class,
            'source_id' => random_int(1000, 999999),
            'currency_type_id' => 'PEN',
            'base_amount' => $amountPen * 10,
            'amount' => $amountPen,
            'amount_pen' => $amountPen,
            'payable_to_sunat' => true,
            'state' => TreasuryWithholding::STATE_ACTIVE,
        ], $overrides));
    }

    public function test_voiding_the_tax_payment_releases_the_settled_withholdings(): void
    {
        $service = $this->makeRetentionService();
        $row = $this->makePayableWithholding(12.00);
        $other = $this->makePayableWithholding(8.00);
        $tax = $this->makeTaxPayment(50);

        $this->assertSame(2, $service->settleWithTaxPayment([$row->id, $other->id], $tax->id));
        $this->assertSame(0.0, $service->listWithholdings([])['sunat_payable']['total_pen']);

        // While settled the record cannot be voided.
        try {
            $service->voidWithholding($row->id, 'Error', 1);
            $this->fail('Expected ValidationException for a settled record.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('withholding', $e->errors());
        }

        $this->makeMovementService()->void($tax->id, 'Pago duplicado', 1);

        $this->assertNull($row->fresh()->settled_movement_id);
        $this->assertNull($other->fresh()->settled_movement_id);
        // Back in the SUNAT payable list ...
        $this->assertSame(20.00, $service->listWithholdings([])['sunat_payable']['total_pen']);
        // ... and now voidable / settleable again with a new payment.
        $this->assertSame(TreasuryWithholding::STATE_VOIDED, $service->voidWithholding($row->id, 'Se emitió por error', 1)->state);
        $newTax = $this->makeTaxPayment(10);
        $this->assertSame(1, $service->settleWithTaxPayment([$other->id], $newTax->id));
    }

    public function test_a_tax_payment_cannot_settle_more_than_its_amount_across_batches(): void
    {
        $service = $this->makeRetentionService();
        $a = $this->makePayableWithholding(100.00);
        $b = $this->makePayableWithholding(100.00);
        $tax = $this->makeTaxPayment(100);

        $this->assertSame(1, $service->settleWithTaxPayment([$a->id], $tax->id));
        try {
            $service->settleWithTaxPayment([$b->id], $tax->id);
            $this->fail('Expected ValidationException: the payment is already fully used.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('movement_id', $e->errors());
            $this->assertStringContainsString('ya liquidado', $e->errors()['movement_id'][0]);
        }
        $this->assertNull($b->fresh()->settled_movement_id);

        // Partial coverage across batches still works while the running total fits.
        $c = $this->makePayableWithholding(40.00);
        $big = $this->makeTaxPayment(150);
        $d = $this->makePayableWithholding(100.00);
        $e = $this->makePayableWithholding(20.00);
        $this->assertSame(1, $service->settleWithTaxPayment([$d->id], $big->id));
        $this->assertSame(1, $service->settleWithTaxPayment([$c->id], $big->id));
        $this->expectException(ValidationException::class);
        $service->settleWithTaxPayment([$e->id], $big->id);
    }

    public function test_an_issued_certificate_cannot_be_linked_twice_even_if_the_application_check_is_bypassed(): void
    {
        $this->setAgentFlags(true, false);
        $purchase = $this->insertPurchase(['number' => '910', 'total' => 1000.00]);
        $first = $this->insertPurchasePayment($purchase, 400.00, '2026-11-02');
        $second = $this->insertPurchasePayment($purchase, 400.00, '2026-11-03');
        $certificateId = $this->db->table('retentions')->insertGetId([
            'series' => 'R001', 'number' => '80', 'state_type_id' => '05', 'date_of_issue' => '2026-11-02',
            'total_retention' => 12.00, 'supplier' => json_encode(['number' => '20200000010']),
        ]);
        $service = $this->makeRetentionService();

        $link = $service->linkIssuedRetention($first, ['series' => 'R001', 'number' => '80'], 1);
        $this->assertSame('retention:' . $certificateId, $link->fresh()->active_link_key);

        // Plain application path: second payment, same CRE.
        try {
            $service->linkIssuedRetention($second, ['series' => 'R001', 'number' => '80'], 1);
            $this->fail('Expected ValidationException for a reused CRE.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('number', $e->errors());
        }

        // Simulated race: a concurrent transaction already holds the key but the row is invisible to the
        // application-level check (retention_id not yet written). The UNIQUE index must stop it.
        $this->db->table('treasury_withholdings')->where('id', $link->id)->update(['retention_id' => null]);
        try {
            $service->linkIssuedRetention($second, ['series' => 'R001', 'number' => '80'], 1);
            $this->fail('Expected the unique active_link_key to reject the duplicate.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya está enlazado', $e->errors()['number'][0]);
        }
        $this->assertSame(1, TreasuryWithholding::query()->count());

        // Voiding the link frees the key so the certificate can be linked again.
        $this->db->table('treasury_withholdings')->where('id', $link->id)->update(['retention_id' => $certificateId]);
        $voided = $service->voidWithholding($link->id, 'Enlace erróneo', 1);
        $this->assertNull($voided->fresh()->active_link_key);
        $this->assertNotNull($service->linkIssuedRetention($second, ['series' => 'R001', 'number' => '80'], 1));
    }

    public function test_a_perception_certificate_is_protected_by_the_same_unique_key(): void
    {
        $this->setAgentFlags(false, true);
        $document = $this->insertDocument(['number' => '920', 'total' => 1000.00]);
        $first = $this->insertDocumentPayment($document, 500.00, '2026-11-02');
        $second = $this->insertDocumentPayment($document, 500.00, '2026-11-03');
        $certificateId = $this->db->table('perceptions')->insertGetId([
            'series' => 'P001', 'number' => '5', 'state_type_id' => '05', 'date_of_issue' => '2026-11-02',
            'total_perception' => 10.00, 'customer' => json_encode(['number' => '20100000001']),
        ]);
        $service = $this->makeRetentionService();

        $link = $service->linkIssuedPerception($first, ['series' => 'P001', 'number' => '5'], 1);
        $this->assertSame('perception:' . $certificateId, $link->fresh()->active_link_key);

        $this->db->table('treasury_withholdings')->where('id', $link->id)->update(['perception_id' => null]);
        try {
            $service->linkIssuedPerception($second, ['series' => 'P001', 'number' => '5'], 1);
            $this->fail('Expected the unique active_link_key to reject the duplicate.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('number', $e->errors());
        }
    }

    public function test_non_admin_without_establishment_cannot_access_null_establishment_sources_or_withholdings(): void
    {
        $service = $this->makeRetentionService();
        $source = (object) ['user_id' => 2, 'establishment_id' => null];
        $withholding = $this->makePayableWithholding(5.00, ['user_id' => 2, 'establishment_id' => null]);

        foreach ([null, 0] as $establishment) {
            $scope = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => $establishment];

            try {
                $service->assertSourceAccess($source, $scope);
                $this->fail('Expected AuthorizationException for a NULL-establishment source.');
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('permiso', $e->getMessage());
            }
            try {
                $service->assertWithholdingAccess($withholding, $scope);
                $this->fail('Expected AuthorizationException for a NULL-establishment withholding.');
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('permiso', $e->getMessage());
            }
            try {
                $service->voidWithholding($withholding->id, 'Intento', 2, $scope);
                $this->fail('Expected AuthorizationException when voiding.');
            } catch (AuthorizationException $e) {
                $this->assertTrue(true);
            }
        }

        // A matching establishment still works.
        $service->assertSourceAccess((object) ['user_id' => 2, 'establishment_id' => 1], ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1]);
    }

    public function test_link_tolerance_certificate_types_and_document_types_are_config_driven(): void
    {
        $this->setAgentFlags(true, true);
        $service = $this->makeRetentionService();

        // Boletas are not retention documents by config; adding '03' to the config makes them eligible.
        $purchase = $this->insertPurchase(['number' => '930', 'document_type_id' => '03', 'total' => 1000.00]);
        $this->insertPurchasePayment($purchase, 400.00, '2026-11-02');
        $this->assertSame('La retención solo aplica a facturas.', $service->getRetentionOffers($purchase)['reason']);
        config(['treasury.withholding.retention_document_types' => ['01', '03']]);
        $this->assertNull($service->getRetentionOffers($purchase)['reason']);
        config(['treasury.withholding.retention_document_types' => ['01']]);

        // Tolerance: a CRE off by 0.05 links with the default 0.10 and fails when the config is tightened.
        $purchase = $this->insertPurchase(['number' => '931', 'total' => 1000.00]);
        $payment = $this->insertPurchasePayment($purchase, 400.00, '2026-11-02');
        $this->db->table('retentions')->insert([
            'series' => 'R001', 'number' => '90', 'state_type_id' => '05', 'date_of_issue' => '2026-11-02',
            'total_retention' => 12.05, 'supplier' => json_encode(['number' => '20200000010']),
        ]);
        config(['treasury.withholding_link_tolerance' => 0.01]);
        try {
            $service->linkIssuedRetention($payment, ['series' => 'R001', 'number' => '90'], 1);
            $this->fail('Expected ValidationException with a tight tolerance.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no coincide', $e->errors()['number'][0]);
        }
        config(['treasury.withholding_link_tolerance' => 0.10]);
        config(['treasury.withholding.retention_certificate_type' => '20']);
        $this->assertSame('20', $service->linkIssuedRetention($payment, ['series' => 'R001', 'number' => '90'], 1)->certificate_type);
    }
}
