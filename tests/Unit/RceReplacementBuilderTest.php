<?php

namespace Tests\Unit;

use App\CoreFacturalo\Services\Sire\RceReplacementBuilder as B;
use PHPUnit\Framework\TestCase;

class RceReplacementBuilderTest extends TestCase
{
    private function doc(array $o = []): array
    {
        return array_merge([
            'document_type_id' => '01', 'series' => 'F001', 'number' => 25, 'date_of_issue' => '2026-10-05',
            'due_date' => '2026-10-05', 'state_type_id' => '01', 'sire_destination' => 'DG',
            'supplier' => ['identity_document_type_id' => '6', 'number' => '20100070970', 'name' => 'ACME | SAC'],
            'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1,
            'total_taxed' => 100, 'total_igv' => 18, 'total_exonerated' => 0, 'total_unaffected' => 0,
            'total_exportation' => 0, 'total_isc' => 0, 'total_other_taxes' => 0,
            'total' => 118, 'affected' => [],
        ], $o);
    }

    private function row(array $o = []): array
    {
        return B::mapRow($this->doc($o), '10767283747', 'KeraAI', '202610');
    }

    private function sum(array $r): float
    {
        $s = 0.0;
        for ($i = 14; $i <= 23; $i++) {
            $s += (float) $r[$i];
        }

        return round($s, 2);
    }

    public function test_factura_pen_dg(): void
    {
        $r = $this->row();
        $this->assertCount(41, $r);
        $this->assertSame('', $r[3]);
        $this->assertSame('', $r[36]);
        $this->assertSame(['', '', '', ''], array_slice($r, 37, 4));
        $this->assertSame('05/10/2026', $r[4]);
        $this->assertSame('05/10/2026', $r[5]);
        $this->assertSame(['01', 'F001', '', '25', ''], array_slice($r, 6, 5));
        $this->assertSame(['6', '20100070970', 'ACME SAC'], array_slice($r, 11, 3));
        $this->assertSame(['100.00', '18.00', '0.00', '0.00', '0.00', '0.00', '0.00'], array_slice($r, 14, 7));
        $this->assertSame(['0.00', '0.00', '0.00', '118.00', 'PEN', '1.000'], array_slice($r, 21, 6));
        $this->assertSame(['', '', '', '', ''], array_slice($r, 27, 5));
    }

    public function test_destination_dgng_and_dng(): void
    {
        $r = $this->row(['sire_destination' => 'DGNG']);
        $this->assertSame(['0.00', '0.00', '100.00', '18.00', '0.00', '0.00'], array_slice($r, 14, 6));
        $r = $this->row(['sire_destination' => 'DNG']);
        $this->assertSame(['0.00', '0.00', '0.00', '0.00', '100.00', '18.00'], array_slice($r, 14, 6));
        $this->assertSame(118.0, $this->sum($r));
    }

    public function test_exonerated_and_unaffected_go_to_non_taxed_value(): void
    {
        $r = $this->row(['total_taxed' => 0, 'total_igv' => 0, 'total_exonerated' => 60, 'total_unaffected' => 40, 'total' => 100]);
        $this->assertSame(['0.00', '0.00'], array_slice($r, 14, 2));
        $this->assertSame('100.00', $r[20]);
        $this->assertSame('100.00', $r[24]);
    }

    public function test_usd_is_converted_and_sums_exactly(): void
    {
        $r = $this->row(['currency_type_id' => 'USD', 'exchange_rate_sale' => 3.555, 'total_taxed' => 10.01, 'total_igv' => 1.80, 'total' => 11.81]);
        $this->assertSame('41.98', $r[24]);
        $this->assertSame(['USD', '3.555'], array_slice($r, 25, 2));
        $this->assertSame(41.98, $this->sum($r));
    }

    public function test_credit_note_is_negative_with_modified_document(): void
    {
        $r = $this->row([
            'document_type_id' => '07', 'series' => 'FC01', 'number' => 3,
            'affected' => [['date' => '2026-09-28', 'type' => '01', 'series' => 'F001', 'number' => 20]],
        ]);
        $this->assertSame(['-100.00', '-18.00', '-118.00'], [$r[14], $r[15], $r[24]]);
        $this->assertSame(['28/09/2026', '01', 'F001', '', '20'], array_slice($r, 27, 5));
        $this->assertSame('118.00', $this->row(['document_type_id' => '08'])[24]);
    }

    public function test_filename(): void
    {
        $this->assertSame('LE1076728374720261000080400021112.txt', B::filename('10767283747', 2026, 10, true, true));
        $this->assertSame('LE1076728374720261000080400020012.zip', B::filename('10767283747', 2026, 10, false, false, 1, 'zip'));
    }

    public function test_empty_period(): void
    {
        $r = B::buildFromDocuments([], '10767283747', 'KeraAI', 2026, 10);
        $this->assertSame('LE1076728374720261000080400020012.txt', $r['filename']);
        $this->assertSame('', $r['txt']);
        $this->assertSame(0, $r['rows_count']);
        $this->assertSame('LE1076728374720261000080400020012.zip', $r['zip_filename']);
    }

    public function test_blocking_errors(): void
    {
        $r = B::buildFromDocuments([$this->doc(['currency_type_id' => 'USD', 'exchange_rate_sale' => 0])], '10767283747', 'KeraAI', 2026, 10);
        $this->assertSame([1, 0, ''], [$r['errors_count'], $r['rows_count'], $r['txt']]);

        $this->assertCount(1, B::blockingErrors($this->doc(['document_type_id' => '07', 'series' => 'FC01', 'affected' => []])));
        $this->assertCount(1, B::blockingErrors($this->doc(['supplier' => ['identity_document_type_id' => '6', 'number' => '20100070971', 'name' => 'X']])));
        $this->assertCount(1, B::blockingErrors($this->doc(['supplier' => ['identity_document_type_id' => '0', 'number' => '', 'name' => '']])));
        $this->assertCount(1, B::blockingErrors($this->doc(['sire_destination' => 'XX'])));
        // Boletas do not require a supplier RUC.
        $this->assertSame([], B::blockingErrors($this->doc(['document_type_id' => '03', 'supplier' => ['identity_document_type_id' => '0', 'number' => '', 'name' => '']])));
    }

    public function test_voided_and_unsupported_are_excluded_with_warnings(): void
    {
        $r = B::buildFromDocuments([
            $this->doc(),
            $this->doc(['number' => 26, 'state_type_id' => '11']),
            $this->doc(['number' => 27, 'document_type_id' => 'GU75']),
        ], '10767283747', 'KeraAI', 2026, 10);
        $this->assertSame([1, 0], [$r['rows_count'], $r['errors_count']]);
        $this->assertSame(2, $r['warnings_count']);
        $this->assertStringContainsString('F001-26', $r['warnings'][0]);
        $this->assertStringContainsString('F001-27 (GU75)', $r['warnings'][1]);
        $this->assertSame(118.0, $r['totals']['total']);
        $this->assertSame([100.0, 18.0, 0.0], [$r['totals']['taxed'], $r['totals']['igv'], $r['totals']['ng']]);
        $this->assertCount(1, explode("\n", $r['txt']));
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

    public function test_supplier_without_identification_keeps_name_only(): void
    {
        $r = $this->row(['document_type_id' => '03', 'supplier' => ['identity_document_type_id' => '0', 'number' => '', 'name' => 'VARIOS']]);
        $this->assertSame(['', '', 'VARIOS'], array_slice($r, 11, 3));
    }

    public function test_period_validation(): void
    {
        $now = \Carbon\Carbon::parse('2026-11-14');
        $this->assertSame([2026, 10], B::validatePeriod('202610', $now));
        $this->expectException(\InvalidArgumentException::class);
        B::validatePeriod('202612', $now);
    }
}
