<?php

namespace Tests\Unit\Treasury;

use Illuminate\Validation\ValidationException;
use Modules\Treasury\Support\StatementParser;
use PHPUnit\Framework\TestCase;

/**
 * Parsing of bank statement files: CSV/Excel layouts, thousands separators, negatives in parentheses, date formats.
 */
class StatementParserTest extends TestCase
{
    use Phase4Harness; // provides the validator needed by ValidationException::withMessages()

    private $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function tempFile(string $contents, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt_') . '.' . $extension;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function signedConfig(array $overrides = []): array
    {
        return array_merge([
            'header_row' => 1,
            'date_column' => 1,
            'description_column' => 2,
            'reference_column' => 3,
            'amount_mode' => 'signed',
            'amount_column' => 4,
            'date_format' => 'd/m/Y',
            'decimal_separator' => '.',
        ], $overrides);
    }

    public function test_parses_signed_csv_with_thousands_separators_and_negative_parentheses(): void
    {
        $csv = "Fecha,Descripcion,Referencia,Importe\n"
            . "05/11/2026,\"ABONO TRANSFERENCIA\",OP123456,\"1,250.50\"\n"
            . "06/11/2026,PAGO PROVEEDOR,OP777,(300.00)\n"
            . "07/11/2026,COMISION,,-15.00\n"
            . "08/11/2026,CARGO,,200.00-\n"
            . "\n"
            . "09/11/2026,Intereses,,S/ 2.50\n";

        $parser = new StatementParser();
        $rows = $parser->readRows($this->tempFile($csv, 'csv'), 'csv');
        $result = $parser->parse($rows, $this->signedConfig());

        $this->assertSame([], $result['errors']);
        $this->assertCount(5, $result['lines']);
        $this->assertSame([1250.50, -300.00, -15.00, -200.00, 2.50], array_column($result['lines'], 'amount'));
        $this->assertSame('2026-11-05', $result['lines'][0]['date']);
        $this->assertSame('OP123456', $result['lines'][0]['reference']);
        $this->assertNull($result['lines'][2]['reference']);
        $this->assertSame(1, $result['lines'][0]['line_no']);
        $this->assertSame(5, $result['lines'][4]['line_no']);
        $this->assertSame(1250.50 + 2.50, $result['totals']['inflow']);
        $this->assertSame('2026-11-05', $result['totals']['date_from']);
        $this->assertSame('2026-11-09', $result['totals']['date_to']);
    }

    public function test_parses_debit_credit_layout_semicolon_delimiter_and_decimal_comma(): void
    {
        $csv = "Fecha;Detalle;Nro;Cargo;Abono\n"
            . "2026-11-05;DEPOSITO;1234;;1.250,50\n"
            . "2026-11-06;RETIRO;1235;300,00;\n"
            . "2026-11-07;ITF;;0,05;\n";

        $parser = new StatementParser();
        $rows = $parser->readRows($this->tempFile($csv, 'csv'), 'csv');
        $result = $parser->parse($rows, [
            'header_row' => 1,
            'date_column' => 1,
            'description_column' => 2,
            'reference_column' => 3,
            'amount_mode' => 'debit_credit',
            'debit_column' => 4,
            'credit_column' => 5,
            'date_format' => 'Y-m-d',
            'decimal_separator' => ',',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame([1250.50, -300.00, -0.05], array_column($result['lines'], 'amount'));
        $this->assertSame(1250.50, $result['totals']['inflow']);
        $this->assertSame(-300.05, $result['totals']['outflow']);
    }

    public function test_debit_credit_with_both_values_or_invalid_numbers_reports_row_errors(): void
    {
        $parser = new StatementParser();
        $rows = [
            ['Fecha', 'Desc', 'Cargo', 'Abono'],
            ['05/11/2026', 'AMBOS', '10.00', '20.00'],
            ['06/11/2026', 'MAL', 'abc', ''],
            ['07/11/2026', 'OK', '5.00', ''],
        ];

        $result = $parser->parse($rows, [
            'header_row' => 1,
            'date_column' => 1,
            'description_column' => 2,
            'amount_mode' => 'debit_credit',
            'debit_column' => 3,
            'credit_column' => 4,
            'date_format' => 'd/m/Y',
        ]);

        $this->assertSame(2, $result['error_count']);
        $this->assertStringContainsString('Fila 2', $result['errors'][0]);
        $this->assertStringContainsString('Fila 3', $result['errors'][1]);
        $this->assertCount(1, $result['lines']);
    }

    public function test_invert_sign_for_banks_that_report_withdrawals_as_positive(): void
    {
        $parser = new StatementParser();
        $result = $parser->parse([
            ['d', 'x', 'r', 'a'],
            ['05/11/2026', 'RETIRO', '', '100.00'],
        ], $this->signedConfig(['invert_sign' => true]));

        $this->assertSame(-100.00, $result['lines'][0]['amount']);
    }

    public function test_date_formats_and_invalid_dates(): void
    {
        $parser = new StatementParser();

        $this->assertSame('2026-11-05', $parser->parseDate('05/11/2026', 'd/m/Y'));
        $this->assertSame('2026-11-05', $parser->parseDate('5/11/2026', 'd/m/Y'));
        $this->assertSame('2026-11-05', $parser->parseDate('05-11-2026', 'd-m-Y'));
        $this->assertSame('2026-11-05', $parser->parseDate('05/11/26', 'd/m/y'));
        $this->assertSame('2026-11-05', $parser->parseDate('11/05/2026', 'm/d/Y'));
        $this->assertSame('2026-11-05', $parser->parseDate('2026-11-05 00:00:00', 'Y-m-d'));
        $this->assertSame('2026-11-05', $parser->parseDate('05/11/2026 14:33', 'd/m/Y'));
        $this->assertNull($parser->parseDate('31/02/2026', 'd/m/Y'));
        $this->assertNull($parser->parseDate('2026-11-05', 'd/m/Y'));
        $this->assertNull($parser->parseDate('garbage', 'Y-m-d'));
        $this->assertSame('2026-11-05', $parser->parseDate(46331, 'excel')); // Excel serial of 2026-11-05
        $this->assertSame('2026-11-05', $parser->parseDate(46331.0, 'd/m/Y')); // spreadsheet cells arrive as numbers
    }

    public function test_parse_amount_edge_cases(): void
    {
        $parser = new StatementParser();

        $this->assertSame(1234567.89, $parser->parseAmount('1,234,567.89', '.'));
        $this->assertSame(1234567.89, $parser->parseAmount('1.234.567,89', ','));
        $this->assertSame(-45.10, $parser->parseAmount('(45.10)', '.'));
        $this->assertSame(-45.10, $parser->parseAmount('45.10-', '.'));
        $this->assertSame(-45.10, $parser->parseAmount('-45.10', '.'));
        $this->assertSame(45.10, $parser->parseAmount('+45.10', '.'));
        $this->assertSame(1500.0, $parser->parseAmount("S/ 1,500", '.'));
        $this->assertSame(75.0, $parser->parseAmount("\u{00A0}75.00\u{00A0}", '.'));
        $this->assertSame(12.5, $parser->parseAmount(12.5, '.'));
        $this->assertNull($parser->parseAmount('', '.'));
        $this->assertNull($parser->parseAmount('12.3.4', '.'));
        $this->assertNull($parser->parseAmount('abc', '.'));
    }

    public function test_windows_1252_encoding_and_bom_are_handled(): void
    {
        $utf8 = "\xEF\xBB\xBFFecha,Descripcion,Ref,Importe\n05/11/2026,ABONO N" . "\u{00D3}" . "MINA,,100.00\n";
        $parser = new StatementParser();
        $rows = $parser->readRows($this->tempFile($utf8, 'csv'), 'csv');
        $this->assertSame('Fecha', $rows[0][0]);

        $latin = mb_convert_encoding("Fecha,Descripcion,Ref,Importe\n05/11/2026,ABONO N" . "\u{00D3}" . "MINA,,100.00\n", 'Windows-1252', 'UTF-8');
        $rows = $parser->readRows($this->tempFile($latin, 'csv'), 'csv');
        $result = $parser->parse($rows, $this->signedConfig());
        $this->assertSame("ABONO N\u{00D3}MINA", $result['lines'][0]['description']);
    }

    public function test_reads_excel_files_with_date_serials_and_numeric_amounts(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Fecha', 'Descripcion', 'Ref', 'Importe'],
            [46331, 'ABONO', 'OP1', 1500.25],
            [46332, 'CARGO', 'OP2', -300.75],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'stmt_') . '.xlsx';
        $this->tempFiles[] = $path;
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        $parser = new StatementParser();
        $rows = $parser->readRows($path, 'xlsx');
        $result = $parser->parse($rows, $this->signedConfig(['date_format' => 'excel']));

        $this->assertSame([], $result['errors']);
        $this->assertSame(['2026-11-05', '2026-11-06'], array_column($result['lines'], 'date'));
        $this->assertSame([1500.25, -300.75], array_column($result['lines'], 'amount'));
    }

    public function test_invalid_mapping_and_extensions_are_rejected_with_field_keys(): void
    {
        $parser = new StatementParser();

        try {
            $parser->normalizeConfig(['amount_mode' => 'debit_credit', 'date_column' => 1, 'description_column' => 2, 'date_format' => 'd/m/Y']);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mapping.debit_column', $e->errors());
            $this->assertArrayHasKey('mapping.credit_column', $e->errors());
        }

        try {
            $parser->normalizeConfig(['date_column' => 0, 'description_column' => 2, 'amount_column' => 3, 'date_format' => 'zzz']);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mapping.date_column', $e->errors());
            $this->assertArrayHasKey('mapping.date_format', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $parser->readRows($this->tempFile('x', 'exe'), 'exe');
    }

    public function test_row_limit_is_enforced(): void
    {
        $parser = new StatementParser(3);
        $rows = [['h']];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = ['05/11/2026', 'x', '', '1.00'];
        }

        $this->expectException(ValidationException::class);
        $parser->parse($rows, $this->signedConfig());
    }

    private function saveXlsx(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt_') . '.xlsx';
        $this->tempFiles[] = $path;
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false); // keep the cached results exactly as the test set them
        $writer->save($path);

        return $path;
    }

    public function test_excel_with_a_stray_far_cell_is_rejected_without_exhausting_memory(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Fecha', 'Descripcion', 'Ref', 'Importe'], [46331, 'ABONO', 'OP1', 10.00]], null, 'A1');
        $sheet->setCellValue('XFD100000', 'x');
        $path = $this->saveXlsx($spreadsheet);

        $before = memory_get_peak_usage(true);
        try {
            (new StatementParser(100, 30))->readRows($path, 'xlsx');
            $this->fail('A far-away cell must be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('demasiado grande', implode(' ', $e->errors()['file']));
        }
        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $before);

        // Too many columns inside the row window is rejected too.
        $wide = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $wide->getActiveSheet()->setCellValue('AZ1', 'x'); // column 52 > 30
        $this->expectException(ValidationException::class);
        (new StatementParser(100, 30))->readRows($this->saveXlsx($wide), 'xlsx');
    }

    public function test_excel_formulas_are_not_evaluated_and_cached_values_are_used(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([['Fecha', 'Descripcion', 'Ref', 'Importe'], [46331, 'ABONO', 'OP1', 0]], null, 'A1');
        // The formula would evaluate to 3, the cached result stored in the file is 777.
        $sheet->setCellValue('D2', '=1+2');
        // No cached value at all: must surface as an error cell, never be evaluated.
        $sheet->setCellValue('A3', 46332);
        $sheet->setCellValue('B3', 'CARGO');
        $sheet->setCellValue('D3', '=SUM(1,2,3)');
        $path = $this->saveXlsx($spreadsheet);

        // PhpSpreadsheet always writes a cached "0"; rewrite the sheet like Excel would: D2 caches 777, D3 has none.
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $patched = str_replace(
            ['<c r="D2" t="str"><f>1+2</f><v>0</v></c>', '<c r="D3" t="str"><f>SUM(1,2,3)</f><v>0</v></c>'],
            ['<c r="D2"><f>1+2</f><v>777</v></c>', '<c r="D3"><f>SUM(1,2,3)</f></c>'],
            $xml
        );
        $this->assertNotSame($xml, $patched);
        $zip->addFromString('xl/worksheets/sheet1.xml', $patched);
        $zip->close();

        $parser = new StatementParser();
        $rows = $parser->readRows($path, 'xlsx');

        $this->assertEquals(777, $rows[1][3]);
        $this->assertSame('#N/A', $rows[2][3]);

        $result = $parser->parse($rows, $this->signedConfig(['date_format' => 'excel']));
        $this->assertEquals([777.0], array_column($result['lines'], 'amount'));
        $this->assertSame(1, $result['error_count']);
        $this->assertStringContainsString('Fila 3', $result['errors'][0]);
    }

    public function test_decimal_comma_rejects_ambiguous_thousands_groups_and_mirrors_for_decimal_point(): void
    {
        $parser = new StatementParser();

        $this->assertNull($parser->parseAmount('1.5', ','));
        $this->assertNull($parser->parseAmount('12.34', ','));
        $this->assertNull($parser->parseAmount('1.2345,00', ','));
        $this->assertEquals(1234.0, $parser->parseAmount('1.234', ','));
        $this->assertEquals(1234567.89, $parser->parseAmount('1.234.567,89', ','));
        $this->assertEquals(0.5, $parser->parseAmount('0,5', ','));

        $this->assertNull($parser->parseAmount('1,5', '.'));
        $this->assertNull($parser->parseAmount('1,23', '.'));
        $this->assertEquals(1234.0, $parser->parseAmount('1,234', '.'));
        $this->assertEquals(1250.5, $parser->parseAmount('1,250.50', '.'));

        // As a row error when parsing a file.
        $result = $parser->parse([['h'], ['05/11/2026', 'x', '', '1.5']], $this->signedConfig(['decimal_separator' => ',']));
        $this->assertSame(1, $result['error_count']);
        $this->assertSame([], $result['lines']);
    }
}
