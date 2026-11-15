<?php

namespace Tests\Unit;

use App\CoreFacturalo\Services\Sire\SirePropuestaParser as P;
use App\CoreFacturalo\Services\Sire\SireReconciler as R;
use PHPUnit\Framework\TestCase;

class SireReconcilerTest extends TestCase
{
    private function sys(string $type, string $series, $number, float $total, string $state = '05', string $date = '2026-10-05'): array
    {
        return ['type' => $type, 'series' => $series, 'number' => (string) $number, 'date' => $date, 'total' => $total, 'state' => $state];
    }

    private function sun(string $type, string $series, $number, float $total, $to = null, string $date = '2026-10-05'): array
    {
        return ['type' => $type, 'series' => $series, 'number' => (string) $number, 'number_to' => $to, 'date' => $date, 'total' => $total];
    }

    public function testMatchesIgnoringZeroPaddingAndCase()
    {
        $r = R::reconcile([$this->sun('01', 'f001', '00000025', 118.0)], [$this->sys('01', 'F001', 25, 118.0)]);

        $this->assertSame(1, $r['matched']);
        $this->assertTrue($r['in_sync']);
        $this->assertSame(0.0, $r['difference_total']);
    }

    public function testOnlyInSunatOnlyInSystemAndTotals()
    {
        $r = R::reconcile(
            [$this->sun('01', 'F001', 1, 100.0), $this->sun('01', 'F001', 2, 50.0)],
            [$this->sys('01', 'F001', 1, 100.0), $this->sys('01', 'F001', 3, 30.0)],
            ['01|F001|2' => '03']
        );

        $this->assertSame(1, $r['matched']);
        $this->assertSame(['2'], array_column($r['solo_en_sunat'], 'number'));
        $this->assertStringContainsString('estado 03', $r['solo_en_sunat'][0]['note']);
        $this->assertSame(['3'], array_column($r['solo_en_sistema'], 'number'));
        $this->assertSame(2, $r['sunat']['count']);
        $this->assertSame(150.0, $r['sunat']['total']);
        $this->assertSame(130.0, $r['system']['total']);
        $this->assertSame(20.0, $r['difference_total']);
        $this->assertFalse($r['in_sync']);
    }

    public function testAmountDifferenceBeyondToleranceOnly()
    {
        $r = R::reconcile(
            [$this->sun('01', 'F001', 1, 100.01), $this->sun('01', 'F001', 2, 100.0)],
            [$this->sys('01', 'F001', 1, 100.0), $this->sys('01', 'F001', 2, 90.0)]
        );

        $this->assertSame(2, $r['matched']);
        $this->assertCount(1, $r['diferencias_monto']);
        $this->assertSame('2', $r['diferencias_monto'][0]['number']);
        $this->assertSame(10.0, $r['diferencias_monto'][0]['difference']);
    }

    public function testVoidedInSystemButAmountInSunatIsFlagged()
    {
        $r = R::reconcile([$this->sun('01', 'F001', 1, 118.0)], [$this->sys('01', 'F001', 1, 0.0, '11')]);

        $this->assertCount(1, $r['diferencias_monto']);
        $this->assertStringContainsString('Anulado en el sistema', $r['diferencias_monto'][0]['note']);
    }

    public function testCreditNoteSignIsNormalizedOnBothSides()
    {
        $r = R::reconcile([$this->sun('07', 'FC01', 1, 59.0)], [$this->sys('07', 'FC01', 1, -59.0)]);

        $this->assertTrue($r['in_sync']);
        $this->assertSame(-59.0, $r['sunat']['total']);
    }

    public function testBoletaRangeMatchesSumOfSystemBoletas()
    {
        $r = R::reconcile(
            [$this->sun('03', 'B001', 1, 60.0, '3')],
            [$this->sys('03', 'B001', 1, 10.0), $this->sys('03', 'B001', 2, 20.0), $this->sys('03', 'B001', 3, 30.0)]
        );

        $this->assertTrue($r['in_sync']);
        $this->assertSame(1, $r['matched']);
        $this->assertSame(3, $r['sunat']['count']); // range counts as 3 documents
        $this->assertSame(0, $r['solo_en_sistema_count']);
    }

    public function testBoletaRangeWithDifferentSumIsReported()
    {
        $r = R::reconcile(
            [$this->sun('03', 'B001', 1, 70.0, '3')],
            [$this->sys('03', 'B001', 1, 10.0), $this->sys('03', 'B001', 2, 20.0), $this->sys('03', 'B001', 4, 5.0)]
        );

        $this->assertSame(1, $r['diferencias_rango_count']);
        $this->assertSame(2, $r['diferencias_rango'][0]['system_count']);
        $this->assertSame(40.0, $r['diferencias_rango'][0]['difference']);
        $this->assertSame(['4'], array_column($r['solo_en_sistema'], 'number')); // outside the range
    }

    public function testBoletaRangeWithoutSystemDocumentsIsOnlyInSunat()
    {
        $r = R::reconcile([$this->sun('03', 'B001', 1, 60.0, '3')], []);

        $this->assertSame(1, $r['solo_en_sunat_count']);
        $this->assertSame('1-3', $r['solo_en_sunat'][0]['number']);
    }

    public function testIndividualBoletaLineWinsOverRange()
    {
        $r = R::reconcile(
            [$this->sun('03', 'B001', 5, 10.0), $this->sun('03', 'B001', 1, 30.0, '3')],
            [$this->sys('03', 'B001', 5, 10.0), $this->sys('03', 'B001', 2, 30.0)]
        );

        $this->assertTrue($r['in_sync']);
    }

    public function testListsAreCappedButCountsAreFull()
    {
        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = $this->sun('01', 'F001', $i, 1.0);
        }
        $r = R::reconcile($rows, [], [], 0.01, 2);

        $this->assertSame(5, $r['solo_en_sunat_count']);
        $this->assertCount(2, $r['solo_en_sunat']);
    }

    public function testParserHeaderDrivenCommaFile()
    {
        $txt = "\xEF\xBB\xBFRuc,Apellidos y Nombres o Razón social,Periodo,CAR SUNAT,Fecha de emisión,Fecha Vcto/Pago,Tipo CP/Doc.,Serie del CDP,Nro CP o Doc. Nro Inicial (Rango),Nro Final (Rango),IGV / IPM,Total CP,Moneda,Tipo de Cambio\n"
            ."20123456789,\"ACME, SAC\",202610,CAR1,05/10/2026,,1,F001,00000025,,18.00,118.00,PEN,1.000\n"
            ."20123456789,ACME,202610,,06/10/2026,,03,B001,1,40,0,\"1,234.50\",PEN,1.000\n";

        $out = P::parseText($txt);

        $this->assertTrue($out['header']);
        $this->assertSame(',', $out['delimiter']);
        $this->assertCount(2, $out['rows']);
        $this->assertSame(['01', 'F001', '00000025', null, '2026-10-05', 118.0, 18.0], [
            $out['rows'][0]['type'], $out['rows'][0]['series'], $out['rows'][0]['number'], $out['rows'][0]['number_to'],
            $out['rows'][0]['date'], $out['rows'][0]['total'], $out['rows'][0]['igv'],
        ]);
        $this->assertSame('40', $out['rows'][1]['number_to']);
        $this->assertSame(1234.5, $out['rows'][1]['total']);
    }

    public function testParserPositionalPipeFileWithoutHeader()
    {
        $fields = array_fill(0, 33, '');
        $fields[4] = '05/10/2026';
        $fields[6] = '01';
        $fields[7] = 'F001';
        $fields[8] = '25';
        $fields[25] = '118.00';
        $fields[26] = 'PEN';

        $out = P::parseText(implode('|', $fields));

        $this->assertFalse($out['header']);
        $this->assertSame('|', $out['delimiter']);
        $this->assertSame(['01', 'F001', '25', 118.0, '2026-10-05'], [$out['rows'][0]['type'], $out['rows'][0]['series'], $out['rows'][0]['number'], $out['rows'][0]['total'], $out['rows'][0]['date']]);
    }

    public function testParserZipAndGarbage()
    {
        $path = tempnam(sys_get_temp_dir(), 'z');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('a.txt', "Tipo CP/Doc.|Serie del CDP|Nro CP o Doc. Nro Inicial (Rango)|Total CP\n01|F001|1|10.00\n");
        $zip->addFromString('b.txt', "Tipo CP/Doc.|Serie del CDP|Nro CP o Doc. Nro Inicial (Rango)|Total CP\n01|F001|2|20.00\n");
        $zip->close();
        $binary = file_get_contents($path);
        unlink($path);

        $out = P::parseZip($binary);
        $this->assertCount(2, $out['rows']);

        $this->expectException(\RuntimeException::class);
        P::parseZip('not a zip');
    }
}
