<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Sunat\HospitalityExport;
use PHPUnit\Framework\TestCase;

class HospitalityExportTest extends TestCase
{
    private function inputs(array $override = [], ?array $attributes = null): array
    {
        $attributes = $attributes ?? HospitalityExport::lodgingAttributes('US', 'US', '2026-11-10', '2026-11-12', '2026-11-15', 3);

        return array_replace_recursive([
            'operation_type_id' => '0202',
            'document_type_id' => '01',
            'customer' => ['identity_document_type_id' => '7', 'number' => 'P1234567'],
            'items' => [['affectation_igv_type_id' => '40', 'total_igv' => 0, 'attributes' => $attributes]],
        ], $override);
    }

    private function assertRejected(array $inputs, string $fragment): void
    {
        try {
            HospitalityExport::assert($inputs);
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }

    public function test_valid_document_passes(): void
    {
        HospitalityExport::assert($this->inputs());
        $this->assertTrue(true);
    }

    public function test_other_operation_types_are_ignored(): void
    {
        HospitalityExport::assert(['operation_type_id' => '0101', 'customer' => ['identity_document_type_id' => '1']]);
        $this->assertTrue(true);
    }

    public function test_operation_type_is_read_from_normalized_invoice_block(): void
    {
        $inputs = $this->inputs();
        unset($inputs['operation_type_id']);
        $inputs['invoice'] = ['operation_type_id' => '0202'];
        $inputs['customer']['identity_document_type_id'] = '6';
        $this->assertRejected($inputs, 'pasaporte');
    }

    public function test_customer_must_have_passport(): void
    {
        $this->assertRejected($this->inputs(['customer' => ['identity_document_type_id' => '1']]), 'pasaporte');
    }

    public function test_stay_above_60_days_is_rejected(): void
    {
        // 2026-09-01 -> 2026-11-01 is 61 nights.
        $attrs = HospitalityExport::lodgingAttributes('US', 'US', '2026-08-01', '2026-09-01', '2026-11-01', 61);
        $this->assertRejected($this->inputs([], $attrs), '60');
        // 2026-09-01 -> 2026-10-31 is 60 nights.
        $attrs = HospitalityExport::lodgingAttributes('US', 'US', '2026-08-01', '2026-09-01', '2026-10-31', 60);
        HospitalityExport::assert($this->inputs([], $attrs));
        $this->assertTrue(true);
    }

    public function test_missing_days_reports_error_3145(): void
    {
        $attrs = array_values(array_filter(
            HospitalityExport::lodgingAttributes('US', 'US', '2026-11-10', '2026-11-12', '2026-11-15', 3),
            fn ($a) => $a['attribute_type_id'] !== '4005'
        ));
        $this->assertRejected($this->inputs([], $attrs), '3145');
    }

    public function test_lodging_line_must_not_carry_igv_and_must_exist(): void
    {
        $this->assertRejected($this->inputs(['items' => [['total_igv' => 18]]]), 'IGV');

        $noLodging = $this->inputs();
        $noLodging['items'] = [['affectation_igv_type_id' => '10', 'total_igv' => 18]];
        $this->assertRejected($noLodging, 'exportación');
    }

    public function test_attribute_builder_emits_codes_4000_to_4005(): void
    {
        $codes = array_column(HospitalityExport::lodgingAttributes('US', 'DE', '2026-11-10', '2026-11-12', '2026-11-15', 3), 'attribute_type_id');
        $this->assertSame(['4000', '4001', '4002', '4003', '4004', '4005'], $codes);
    }

    public function test_strict_date_formats(): void
    {
        foreach (['tomorrow', '11/12/26', '2026-1-5', '2026-02-30'] as $bad) {
            $attrs = HospitalityExport::lodgingAttributes('US', 'US', '2026-11-10', $bad, '2026-11-15', 3);
            $this->assertRejected($this->inputs([], $attrs), 'AAAA-MM-DD');
        }
    }

    public function test_entry_after_checkin_is_rejected(): void
    {
        $attrs = HospitalityExport::lodgingAttributes('US', 'US', '2026-11-13', '2026-11-12', '2026-11-15', 3);
        $this->assertRejected($this->inputs([], $attrs), '4002');
    }

    public function test_checkout_must_be_after_checkin(): void
    {
        $attrs = HospitalityExport::lodgingAttributes('US', 'US', '2026-11-10', '2026-11-12', '2026-11-12', 1);
        $this->assertRejected($this->inputs([], $attrs), 'posterior');
    }

    public function test_days_must_match_nights(): void
    {
        $attrs = HospitalityExport::lodgingAttributes('US', 'US', '2026-11-10', '2026-11-12', '2026-11-15', 4);
        $this->assertRejected($this->inputs([], $attrs), 'no coinciden');
    }

    public function test_country_must_exist_in_catalog(): void
    {
        $attrs = HospitalityExport::lodgingAttributes('XX', 'US', '2026-11-10', '2026-11-12', '2026-11-15', 3);
        $exists = fn ($id) => in_array($id, ['US', 'PE'], true);
        $this->assertSame(true, (function () use ($attrs, $exists) {
            try {
                HospitalityExport::assert($this->inputs([], $attrs), $exists);
                return false;
            } catch (\Exception $e) {
                return str_contains($e->getMessage(), 'inválido');
            }
        })());
    }
}
