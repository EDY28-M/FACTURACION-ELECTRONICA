<?php

namespace Tests\Unit;

use App\CoreFacturalo\Services\Sire\RvieReplacementBuilder as B;
use PHPUnit\Framework\TestCase;

class RvieReplacementBuilderTest extends TestCase
{
    private function doc(array $o = []): array
    {
        return array_merge([
            'document_type_id' => '01', 'series' => 'F001', 'number' => 25, 'date_of_issue' => '2026-10-05',
            'due_date' => null, 'state_type_id' => '05',
            'customer' => ['identity_document_type_id' => '6', 'number' => '20100070970', 'name' => 'ACME | SAC'],
            'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1,
            'total_exportation' => 0, 'total_taxed' => 100, 'total_igv' => 18, 'total_exonerated' => 0,
            'total_unaffected' => 0, 'total_isc' => 0, 'total_plastic_bag_taxes' => 0, 'total_other_taxes' => 0,
            'total' => 118, 'affected' => [],
        ], $o);
    }

    private function row(array $o = []): array
    {
        return B::mapRow($this->doc($o), '10767283747', 'KeraAI', '202610');
    }

    public function test_factura_pen(): void
    {
        $r = $this->row();
        $this->assertCount(33, $r);
        $this->assertSame('', $r[3]);
        $this->assertSame('05/10/2026', $r[4]);
        $this->assertSame(['01', 'F001', '25', ''], [$r[6], $r[7], $r[8], $r[9]]);
        $this->assertSame(['6', '20100070970', 'ACME SAC'], [$r[10], $r[11], $r[12]]);
        $this->assertSame(['0.00', '100.00', '0.00', '18.00'], [$r[13], $r[14], $r[15], $r[16]]);
        $this->assertSame(['118.00', 'PEN', '1.000'], [$r[25], $r[26], $r[27]]);
        $this->assertSame('', $r[28]);
    }

    public function test_boleta_generic_customer_leaves_identification_empty(): void
    {
        $r = $this->row([
            'document_type_id' => '03', 'series' => 'B001',
            'customer' => ['identity_document_type_id' => '0', 'number' => '99999999', 'name' => 'CLIENTES VARIOS'],
            'total_taxed' => 50, 'total_igv' => 9, 'total' => 59,
        ]);
        $this->assertSame(['', '', ''], [$r[10], $r[11], $r[12]]);
        $this->assertSame('59.00', $r[25]);
    }

    public function test_credit_note_is_negative_with_modified_document(): void
    {
        $r = $this->row([
            'document_type_id' => '07', 'series' => 'FC01', 'number' => 3,
            'affected' => [['date' => '2026-09-28', 'type' => '01', 'series' => 'F001', 'number' => 20]],
        ]);
        $this->assertSame(['-100.00', '-18.00', '-118.00'], [$r[14], $r[16], $r[25]]);
        $this->assertSame(['28/09/2026', '01', 'F001', '20'], [$r[28], $r[29], $r[30], $r[31]]);
    }

    public function test_debit_note_is_positive(): void
    {
        $this->assertSame('118.00', $this->row(['document_type_id' => '08'])[25]);
    }

    public function test_usd_factura_is_converted_with_exchange_rate(): void
    {
        $r = $this->row(['currency_type_id' => 'USD', 'exchange_rate_sale' => 3.75, 'total_taxed' => 100, 'total_igv' => 18, 'total' => 118]);
        $this->assertSame(['375.00', '67.50', '442.50'], [$r[14], $r[16], $r[25]]);
        $this->assertSame(['USD', '3.750'], [$r[26], $r[27]]);
    }

    public function test_voided_document_is_reported_with_zero_amounts(): void
    {
        $r = $this->row(['state_type_id' => '11']);
        $this->assertSame(['0.00', '0.00', '0.00'], [$r[14], $r[16], $r[25]]);
    }

    public function test_due_date_is_formatted(): void
    {
        $this->assertSame('30/11/2026', $this->row(['due_date' => '2026-11-30'])[5]);
    }

    public function test_filename(): void
    {
        $this->assertSame('LE1076728374720261000140400021112.txt', B::filename('10767283747', 2026, 10, true, true));
        $this->assertSame('LE1076728374720261000140400020012.zip', B::filename('10767283747', 2026, 10, false, false, 1, 'zip'));
    }

    public function test_empty_period_produces_empty_file_with_zero_indicators(): void
    {
        $r = B::buildFromDocuments([], '10767283747', 'KeraAI', 2026, 10);
        $this->assertSame('LE1076728374720261000140400020012.txt', $r['filename']);
        $this->assertSame('', $r['txt']);
        $this->assertSame(0, $r['rows_count']);
        $this->assertSame('LE1076728374720261000140400020012.zip', $r['zip_filename']);
    }

    public function test_period_validation(): void
    {
        $now = \Carbon\Carbon::parse('2026-11-14');
        $this->assertSame([2026, 10], B::validatePeriod('202610', $now));
        $this->expectException(\InvalidArgumentException::class);
        B::validatePeriod('202612', $now);
    }

    public function test_rounding_residual_is_absorbed_so_components_sum_to_total(): void
    {
        $r = $this->row(['currency_type_id' => 'USD', 'exchange_rate_sale' => 3.555, 'total_taxed' => 10.01, 'total_igv' => 1.80, 'total' => 11.81]);
        $this->assertSame('41.98', $r[25]);
        $this->assertSame(41.98, round((float) $r[13] + (float) $r[14] + (float) $r[16] + (float) $r[18] + (float) $r[19] + (float) $r[20] + (float) $r[23] + (float) $r[24], 2));
    }

    public function test_blocking_error_for_foreign_currency_without_rate(): void
    {
        $r = B::buildFromDocuments([$this->doc(['currency_type_id' => 'USD', 'exchange_rate_sale' => 0])], '10767283747', 'KeraAI', 2026, 10);
        $this->assertSame(1, $r['errors_count']);
        $this->assertSame(0, $r['rows_count']);
        $this->assertSame('', $r['txt']);
    }

    public function test_blocking_error_for_note_without_affected_document(): void
    {
        $errors = B::blockingErrors($this->doc(['document_type_id' => '07', 'series' => 'FC01', 'affected' => []]));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('FC01-25', $errors[0]);
    }

    public function test_blocking_error_for_factura_without_ruc_and_note_on_factura(): void
    {
        $noId = ['identity_document_type_id' => '0', 'number' => '', 'name' => ''];
        $this->assertCount(1, B::blockingErrors($this->doc(['customer' => $noId])));
        $this->assertCount(1, B::blockingErrors($this->doc([
            'document_type_id' => '07', 'customer' => $noId,
            'affected' => [['date' => '2026-09-28', 'type' => '01', 'series' => 'F001', 'number' => 20]],
        ])));
    }

    public function test_boleta_blanking_allowed_only_below_threshold(): void
    {
        $noId = ['identity_document_type_id' => '0', 'number' => '99999999', 'name' => 'CLIENTES VARIOS'];
        $this->assertSame([], B::blockingErrors($this->doc(['document_type_id' => '03', 'customer' => $noId, 'total' => 699.9])));
        $this->assertCount(1, B::blockingErrors($this->doc(['document_type_id' => '03', 'customer' => $noId, 'total' => 700])));
        $this->assertSame([], B::blockingErrors($this->doc([
            'document_type_id' => '07', 'customer' => $noId, 'total' => 900,
            'affected' => [['date' => '2026-09-28', 'type' => '03', 'series' => 'B001', 'number' => 20]],
        ])));
    }

    public function test_voided_documents_are_never_blocked(): void
    {
        $this->assertSame([], B::blockingErrors($this->doc(['state_type_id' => '11', 'currency_type_id' => 'USD', 'exchange_rate_sale' => 0])));
    }

    public function test_multiple_documents_totals(): void
    {
        $r = B::buildFromDocuments([
            $this->doc(),
            $this->doc(['number' => 26]),
            $this->doc(['document_type_id' => '07', 'series' => 'FC01', 'affected' => [['date' => '2026-09-28', 'type' => '01', 'series' => 'F001', 'number' => 20]]]),
            $this->doc(['number' => 27, 'state_type_id' => '11']),
        ], '10767283747', 'KeraAI', 2026, 10);
        $this->assertSame(4, $r['rows_count']);
        $this->assertSame(0, $r['errors_count']);
        $this->assertSame(118.00, $r['totals']['total']);
        $this->assertSame(100.00, $r['totals']['taxed']);
        $this->assertSame(3, $r['totals']['by_type']['01']['count']);
        $this->assertSame(1, $r['totals']['by_type']['01']['voided']);
        $this->assertCount(4, explode("\n", $r['txt']));
        $this->assertStringEndsNotWith("\n", $r['txt']);
    }

    public function test_warnings_are_capped(): void
    {
        $docs = [];
        for ($i = 1; $i <= 60; $i++) {
            $docs[] = $this->doc(['number' => $i, 'total' => 120]); // does not add up to 118
        }
        $r = B::buildFromDocuments($docs, '10767283747', 'KeraAI', 2026, 10);
        $this->assertSame(60, $r['warnings_count']);
        $this->assertCount(51, $r['warnings']);
        $this->assertSame('y 10 mas', end($r['warnings']));
    }

    public function test_long_values_are_truncated(): void
    {
        $r = $this->row(['series' => str_repeat('S', 30), 'customer' => ['identity_document_type_id' => '6', 'number' => '20100070970', 'name' => str_repeat('N', 2000)]]);
        $this->assertSame(20, strlen($r[7]));
        $this->assertSame(1500, strlen($r[12]));
    }
}
