<?php

namespace Tests\Unit;

use Modules\Sale\Services\ContractBillingPayload;
use Modules\Sale\Services\ContractBillingService;
use Modules\Sale\Services\RecurrenceSchedule;
use PHPUnit\Framework\TestCase;

class ContractRecurrenceTest extends TestCase
{
    private function dates(array $carbons): array
    {
        return array_map(function ($d) { return $d->format('Y-m-d'); }, $carbons);
    }

    public function test_month_end_start_does_not_drift()
    {
        $dates = $this->dates(RecurrenceSchedule::between('2027-01-31', null, 'month', 1, '2027-01-01', '2027-05-31'));
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31'], $dates);
    }

    public function test_leap_year_february()
    {
        $dates = $this->dates(RecurrenceSchedule::between('2028-01-31', null, 'month', 1, '2028-01-01', '2028-03-31'));
        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31'], $dates);
    }

    public function test_next_advances_from_a_clamped_date_back_to_the_anchor_day()
    {
        $next = RecurrenceSchedule::next('2027-01-31', 'month', 1, '2027-02-28');
        $this->assertSame('2027-03-31', $next->format('Y-m-d'));
    }

    public function test_bimonthly_interval()
    {
        $dates = $this->dates(RecurrenceSchedule::between('2026-10-15', null, 'month', 2, '2026-10-01', '2027-06-30'));
        $this->assertSame(['2026-10-15', '2026-12-15', '2027-02-15', '2027-04-15', '2027-06-15'], $dates);
    }

    public function test_yearly_with_leap_day_start()
    {
        $dates = $this->dates(RecurrenceSchedule::between('2028-02-29', null, 'year', 1, '2028-01-01', '2031-12-31'));
        $this->assertSame(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28'], $dates);
    }

    public function test_end_date_caps_the_series()
    {
        $dates = $this->dates(RecurrenceSchedule::between('2026-10-15', '2027-01-14', 'month', 1, '2026-10-01', '2027-12-31'));
        $this->assertSame(['2026-10-15', '2026-11-15', '2026-12-15'], $dates);

        $this->assertNull(RecurrenceSchedule::advance('2026-10-15', '2026-12-31', 'month', 1, '2026-12-15'));
        $this->assertSame('2026-12-15', RecurrenceSchedule::advance('2026-10-15', '2026-12-31', 'month', 1, '2026-11-15')->format('Y-m-d'));
    }

    public function test_custom_billing_day_and_first_occurrence()
    {
        // Start on the 20th, bill on the 5th: the first billing is the 5th of the next month.
        $first = RecurrenceSchedule::first('2026-10-20', 'month', 1, 5);
        $this->assertSame('2026-11-05', $first->format('Y-m-d'));
        // Day 31 clamps to the last day of shorter months.
        $this->assertSame('2026-11-30', RecurrenceSchedule::next('2026-10-01', 'month', 1, '2026-10-31', 31)->format('Y-m-d'));
    }

    public function test_invalid_period_is_rejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        RecurrenceSchedule::first('2026-10-01', 'week', 1);
    }

    public function test_period_label()
    {
        $this->assertSame('11/2026', RecurrenceSchedule::periodLabel('2026-11-15'));
    }

    public function test_due_selection()
    {
        $base = [
            'recurrence_enabled' => true, 'state_type_id' => '01',
            'recurrence_start_date' => '2026-10-15', 'recurrence_end_date' => null,
            'next_billing_date' => '2026-11-15',
        ];

        $this->assertTrue(ContractBillingService::isDue($base, '2026-11-15'));
        $this->assertTrue(ContractBillingService::isDue($base, '2026-11-20'));
        $this->assertFalse(ContractBillingService::isDue($base, '2026-11-14'));
        $this->assertFalse(ContractBillingService::isDue(array_merge($base, ['recurrence_enabled' => false]), '2026-11-15'));
        $this->assertFalse(ContractBillingService::isDue(array_merge($base, ['state_type_id' => '11']), '2026-11-15'));
        $this->assertFalse(ContractBillingService::isDue(array_merge($base, ['state_type_id' => '09']), '2026-11-15'));
        $this->assertTrue(ContractBillingService::isDue(array_merge($base, ['state_type_id' => '05']), '2026-11-15'));
        $this->assertFalse(ContractBillingService::isDue(array_merge($base, ['recurrence_end_date' => '2026-11-14']), '2026-11-20'));
        $this->assertTrue(ContractBillingService::isDue(array_merge($base, ['recurrence_end_date' => '2026-11-15']), '2026-11-20'));
        $this->assertFalse(ContractBillingService::isDue(array_merge($base, ['next_billing_date' => null]), '2026-11-20'));
        $this->assertFalse(ContractBillingService::isDue(array_merge($base, ['recurrence_start_date' => '2026-12-01']), '2026-11-20'));
    }

    public function test_initial_next_billing_date()
    {
        $this->assertSame('2026-10-15', ContractBillingService::initialNextBillingDate([
            'recurrence_enabled' => true, 'recurrence_start_date' => '2026-10-15',
            'recurrence_period' => 'month', 'recurrence_interval' => 1,
        ], '2026-10-01'));
        $this->assertNull(ContractBillingService::initialNextBillingDate(['recurrence_enabled' => false]));
        $this->assertNull(ContractBillingService::initialNextBillingDate([
            'recurrence_enabled' => true, 'recurrence_start_date' => '2026-10-15', 'recurrence_end_date' => '2026-10-01',
            'recurrence_period' => 'month', 'recurrence_interval' => 1,
        ], '2026-10-01'));
    }

    private function contract(array $overrides = []): array
    {
        return array_merge([
            'id' => 7, 'number_full' => 'CNT-7', 'establishment_id' => 1, 'customer_id' => 3,
            'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1, 'has_global_adjustments' => false,
            'items' => [[
                'item_id' => 1, 'item' => ['unit_type_id' => 'ZZ'], 'quantity' => 2, 'unit_price' => 118,
                'unit_value' => 100, 'price_type_id' => '01', 'affectation_igv_type_id' => '10',
                'percentage_igv' => 18, 'total_base_igv' => 200, 'total_igv' => 36, 'total_taxes' => 36,
                'total_value' => 200, 'total' => 236, 'discounts' => [], 'charges' => [],
            ]],
        ], $overrides);
    }

    private function options(array $overrides = []): array
    {
        return array_merge([
            'document_type_id' => '01', 'series_id' => 1, 'billing_date' => '2026-11-15',
            'reduced_regime' => false, 'send_email' => false, 'time_of_issue' => '10:00:00',
        ], $overrides);
    }

    public function test_payload_general_regime_keeps_price_with_igv()
    {
        $p = ContractBillingPayload::build($this->contract(), $this->options());

        $this->assertSame('01', $p['document_type_id']);
        $this->assertSame('2026-11-15', $p['date_of_issue']);
        $this->assertSame('#', $p['number']);
        $this->assertSame('01', $p['payment_condition_id']);
        $this->assertEquals(200.00, $p['total_taxed']);
        $this->assertEquals(36.00, $p['total_igv']);
        $this->assertEquals(236.00, $p['total']);
        $this->assertSame('Servicio del periodo 11/2026 - Contrato CNT-7', $p['additional_information']);
        $this->assertArrayNotHasKey('send_xml_signed', $p['actions']);
    }

    public function test_payload_rates_by_issue_date_in_reduced_regime()
    {
        // 2026 => 10.5 %, 2027 => 15 %: the price with IGV (S/ 118 x 2) is the fixed input.
        $p2026 = ContractBillingPayload::build($this->contract(), $this->options(['reduced_regime' => true, 'issue_date' => '2026-12-15']));
        $this->assertEquals(10.5, $p2026['items'][0]['percentage_igv']);
        $this->assertEquals(213.57, $p2026['total_taxed']);
        $this->assertEquals(22.43, $p2026['total_igv']);
        $this->assertEquals(236.00, $p2026['total']);

        $p2027 = ContractBillingPayload::build($this->contract(), $this->options(['reduced_regime' => true, 'issue_date' => '2027-01-15', 'billing_date' => '2027-01-15']));
        $this->assertEquals(15.0, $p2027['items'][0]['percentage_igv']);
        $this->assertEquals(205.22, $p2027['total_taxed']);
        $this->assertEquals(30.78, $p2027['total_igv']);
        $this->assertEquals(236.00, $p2027['total']);
    }

    public function test_payload_item_with_general_rate_flag_ignores_reduced_regime()
    {
        $contract = $this->contract();
        $contract['items'][0]['igv_general_rate'] = true;
        $p = ContractBillingPayload::build($contract, $this->options(['reduced_regime' => true]));
        $this->assertEquals(18.0, $p['items'][0]['percentage_igv']);
        $this->assertEquals(36.0, $p['total_igv']);
    }

    public function test_payload_boleta_vs_factura_and_forced_no_send()
    {
        $factura = ContractBillingPayload::build($this->contract(), $this->options(['document_type_id' => '01', 'series_id' => 1]));
        $boleta = ContractBillingPayload::build($this->contract(), $this->options(['document_type_id' => '03', 'series_id' => 2, 'send_xml_signed' => false]));

        $this->assertSame(['01', 1], [$factura['document_type_id'], $factura['series_id']]);
        $this->assertSame(['03', 2], [$boleta['document_type_id'], $boleta['series_id']]);
        $this->assertFalse($boleta['actions']['send_xml_signed']);
        $this->assertSame($factura['total'], $boleta['total']);
    }

    public function test_payload_keeps_non_taxed_rows_untouched_and_totals_them()
    {
        $contract = $this->contract();
        $contract['items'][] = [
            'item_id' => 2, 'item' => ['unit_type_id' => 'NIU'], 'quantity' => 1, 'unit_price' => 50,
            'unit_value' => 50, 'price_type_id' => '01', 'affectation_igv_type_id' => '20',
            'percentage_igv' => 0, 'total_base_igv' => 50, 'total_igv' => 0, 'total_taxes' => 0,
            'total_value' => 50, 'total' => 50, 'discounts' => [], 'charges' => [],
        ];
        $p = ContractBillingPayload::build($contract, $this->options(['reduced_regime' => true, 'issue_date' => '2027-02-01']));

        $this->assertEquals(50.0, $p['total_exonerated']);
        $this->assertEquals(50.0, $p['items'][1]['total_value']);
        $this->assertEquals(round($p['total_taxed'] + $p['total_igv'] + $p['total_exonerated'], 2), $p['total']);
    }

    public function test_payload_rejects_global_adjustments_and_empty_items()
    {
        try {
            ContractBillingPayload::build($this->contract(['has_global_adjustments' => true]), $this->options());
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('globales', $e->getMessage());
        }

        $this->expectException(\Exception::class);
        ContractBillingPayload::build($this->contract(['items' => []]), $this->options());
    }

    public function test_idempotency_guard_refuses_an_issued_period_but_allows_retrying_a_failed_one()
    {
        $issued = new \Modules\Sale\Models\ContractBilling(['status' => 'issued']);
        $failed = new \Modules\Sale\Models\ContractBilling(['status' => 'failed']);

        ContractBillingService::assertNotAlreadyIssued($failed, 'CNT-7', '2026-11-15');
        ContractBillingService::assertNotAlreadyIssued(null, 'CNT-7', '2026-11-15');

        $this->expectException(\Modules\Sale\Services\ContractAlreadyBilledException::class);
        ContractBillingService::assertNotAlreadyIssued($issued, 'CNT-7', '2026-11-15');
    }

    public function test_payload_has_no_one_cent_drift()
    {
        $contract = $this->contract();
        $contract['items'][0]['unit_price'] = 10.50;
        $contract['items'][0]['quantity'] = 3;
        $p = ContractBillingPayload::build($contract, $this->options());

        $this->assertEquals(31.50, $p['total']);
        $this->assertEquals(26.69, $p['total_taxed']);
        $this->assertEquals(4.81, $p['total_igv']);
        $this->assertEquals(31.50, round($p['total_taxed'] + $p['total_igv'], 2));
    }

    public function test_payload_rejects_rows_with_isc_or_other_taxes()
    {
        $contract = $this->contract();
        $contract['items'][0]['total_isc'] = 5;
        $this->expectException(\Exception::class);
        ContractBillingPayload::build($contract, $this->options());
    }

    public function test_period_guard_requires_today_or_before_and_after_start()
    {
        ContractBillingService::assertPeriodInRange('2026-11-15', '2026-10-15', '2026-11-15');
        foreach ([['2026-11-16', '2026-10-15'], ['2026-09-15', '2026-10-15']] as [$period, $start]) {
            try {
                ContractBillingService::assertPeriodInRange($period, $start, '2026-11-15');
                $this->fail("Expected rejection for {$period}");
            } catch (\Exception $e) {
                $this->assertStringContainsString('periodo', $e->getMessage());
            }
        }
    }

    public function test_initial_next_billing_date_backdated_start()
    {
        $data = ['recurrence_enabled' => true, 'recurrence_start_date' => '2026-08-15',
            'recurrence_period' => 'month', 'recurrence_interval' => 1];

        // Default: skip the past periods, first billing on or after today.
        $this->assertSame('2026-11-15', ContractBillingService::initialNextBillingDate($data, '2026-11-15'));
        $this->assertSame('2026-12-15', ContractBillingService::initialNextBillingDate($data, '2026-11-16'));
        // Backfill: keep the first occurrence of the series.
        $this->assertSame('2026-08-15', ContractBillingService::initialNextBillingDate($data + ['recurrence_backfill' => true], '2026-11-15'));
        // Future start is untouched.
        $this->assertSame('2027-01-10', ContractBillingService::initialNextBillingDate(
            ['recurrence_start_date' => '2027-01-10'] + $data, '2026-11-15'));
    }

    public function test_automatic_retry_cap()
    {
        $this->assertFalse(ContractBillingService::retryCapReached(0));
        $this->assertFalse(ContractBillingService::retryCapReached(ContractBillingService::MAX_AUTOMATIC_ATTEMPTS - 1));
        $this->assertTrue(ContractBillingService::retryCapReached(ContractBillingService::MAX_AUTOMATIC_ATTEMPTS));
    }
}
