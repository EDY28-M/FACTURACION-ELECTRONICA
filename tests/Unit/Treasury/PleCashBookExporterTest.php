<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\DocumentPayment;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Treasury\Models\PaymentEvidence;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\CashBookReport;
use Modules\Treasury\Services\PleCashBookExporter;
use Modules\Treasury\Support\PleExportException;
use PHPUnit\Framework\TestCase;

/**
 * PLE TXT of the Libro Caja y Bancos (010100 / 010200). Expected lines follow the official layout of the
 * "Estructura del PLE" workbook (RS 286-2009/SUNAT Anexo 2): 19 fields for 1.1, 15 fields for 1.2.
 */
class PleCashBookExporterTest extends TestCase
{
    use Phase4Harness;

    private const RUC = '20555555551';
    private const CUSTOMER_RUC = '20100070970';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();

        $this->db->statement('alter table companies add column number text null');
        $this->db->statement('alter table companies add column name text null');
        $this->db->table('companies')->where('id', 1)->update(['number' => self::RUC, 'name' => 'Empresa Demo SAC']);
        $this->db->statement('alter table persons add column identity_document_type_id text null');
        $this->db->table('persons')->where('id', 1)->update(['identity_document_type_id' => '6', 'number' => self::CUSTOMER_RUC]);
        $this->db->statement('create table payment_method_types (id text primary key, description text, sunat_code text null, is_cash integer default 0)');
        $this->db->statement('alter table document_payments add column payment_method_type_id text null');
        $this->db->statement('alter table purchase_payments add column payment_method_type_id text null');
        $this->db->statement('create table cash_transactions (id integer primary key autoincrement, cash_id integer, payment_method_type_id text null, date text, description text null, payment numeric)');

        $this->db->table('bank_accounts')->where('id', 1)->update(['sunat_financial_entity_code' => '02']);
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function exporter(): PleCashBookExporter
    {
        return new PleCashBookExporter(new CashBookReport($this->makeBalanceService()));
    }

    private function adminScope(): array
    {
        return ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];
    }

    private function cashId(): int
    {
        $id = $this->db->table('cash')->where('user_id', 1)->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) $this->db->table('cash')->insertGetId([
            'user_id' => 1, 'date_opening' => '2026-11-01', 'time_opening' => '08:00:00', 'beginning_balance' => 0,
            'final_balance' => 0, 'income' => 0, 'state' => 1, 'reference_number' => 'C-01',
        ]);
    }

    private function cashDocumentPayment(string $series, string $number, float $amount, string $date): int
    {
        $docId = $this->insertDocument(['series' => $series, 'number' => $number, 'date_of_issue' => $date, 'total' => $amount]);
        $paymentId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $docId, 'payment' => $amount, 'date_of_payment' => $date,
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => $this->cashId(), 'destination_type' => Cash::class,
            'payment_id' => $paymentId, 'payment_type' => DocumentPayment::class,
        ]);

        return $paymentId;
    }

    private function bankDocumentPayment(string $series, string $number, float $amount, string $date, ?string $paymentCode, string $operation = 'OP123', int $accountId = 1, int $customerId = 1): int
    {
        $docId = $this->insertDocument(['series' => $series, 'number' => $number, 'date_of_issue' => $date, 'total' => $amount, 'customer_id' => $customerId]);
        $paymentId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $docId, 'payment' => $amount, 'date_of_payment' => $date,
        ]);
        $this->db->table('global_payments')->insert([
            'destination_id' => $accountId, 'destination_type' => BankAccount::class,
            'payment_id' => $paymentId, 'payment_type' => DocumentPayment::class,
        ]);
        if ($paymentCode !== null) {
            PaymentEvidence::create([
                'payable_type' => DocumentPayment::class, 'payable_id' => $paymentId, 'sunat_payment_code' => $paymentCode,
                'operation_number' => $operation, 'operation_date' => $date,
            ]);
        }

        return $paymentId;
    }

    private function bankMovement(string $date, float $amount, int $direction, string $description, ?string $code = '003', string $operation = 'TRX9', int $accountId = 1, string $currency = 'PEN'): TreasuryMovement
    {
        return TreasuryMovement::create([
            'establishment_id' => 1, 'date' => $date, 'kind' => $direction > 0 ? 'income' : 'bank_fee', 'direction' => $direction,
            'destination_type' => BankAccount::class, 'destination_id' => $accountId, 'currency_type_id' => $currency,
            'amount' => $amount, 'amount_pen' => $amount, 'sunat_payment_code' => $code, 'operation_number' => $operation,
            'counterparty_name' => 'BCP', 'treasury_concept_id' => 2, 'description' => $description,
            'state' => TreasuryMovement::STATE_ACTIVE, 'user_id' => 1,
        ]);
    }

    private function file(array $result, string $format, string $currency = 'PEN'): array
    {
        foreach ($result['files'] as $file) {
            if ($file['format'] === $format && $file['currency'] === $currency) {
                return $file;
            }
        }
        $this->fail("File {$format}/{$currency} not generated");
    }

    /** @return array<int, string> */
    private function lines(array $file): array
    {
        return $file['content'] === '' ? [] : explode("\r\n", rtrim($file['content'], "\r\n"));
    }

    public function test_empty_period_generates_both_files_with_indicator_zero_and_no_content(): void
    {
        $result = $this->exporter()->buildOrFail('2026-12', $this->adminScope());

        $this->assertSame(self::RUC, $result['ruc']);
        $this->assertCount(2, $result['files']);

        $f11 = $this->file($result, '1.1');
        $f12 = $this->file($result, '1.2');
        $this->assertSame('LE2055555555120261200010100001011.TXT', $f11['filename']);
        $this->assertSame('LE2055555555120261200010200001011.TXT', $f12['filename']);
        $this->assertFalse($f11['has_info']);
        $this->assertFalse($f12['has_info']);
        $this->assertSame('', $f11['content']);
        $this->assertSame('', $f12['content']);
    }

    public function test_file_names_follow_the_official_pattern_for_info_and_currency(): void
    {
        $this->assertSame('LE2055555555120261100010100001111.TXT', PleCashBookExporter::fileName(self::RUC, '2026-11', '010100', true, 'PEN'));
        $this->assertSame('LE2055555555120261100010200001121.TXT', PleCashBookExporter::fileName(self::RUC, '2026-11', '010200', true, 'USD'));
        $this->assertSame('LE2055555555120260100010200001021.TXT', PleCashBookExporter::fileName(self::RUC, '2026-01', '010200', false, 'USD'));
    }

    public function test_format_11_lines_match_the_19_field_layout(): void
    {
        $dp = $this->cashDocumentPayment('F001', '00010', 500.00, '2026-11-05');
        $this->db->table('treasury_concepts')->where('id', 5)->update(['pcge_account' => '6591']);
        $movement = TreasuryMovement::create([
            'establishment_id' => 1, 'date' => '2026-11-10', 'kind' => 'expense', 'direction' => -1,
            'destination_type' => Cash::class, 'destination_id' => $this->cashId(), 'currency_type_id' => 'PEN',
            'amount' => 45.00, 'amount_pen' => 45.00, 'treasury_concept_id' => 5, 'description' => 'Movilidad local',
            'state' => TreasuryMovement::STATE_ACTIVE, 'user_id' => 1,
        ]);

        $result = $this->exporter()->buildOrFail('2026-11', $this->adminScope());
        $file = $this->file($result, '1.1');
        $lines = $this->lines($file);

        $this->assertSame('LE2055555555120261100010100001111.TXT', $file['filename']);
        $this->assertTrue($file['has_info']);
        $this->assertCount(2, $lines);
        $this->assertSame(
            sprintf('20261100|DP%010d|M1|101|||PEN|01|F001|00010|05/11/2026||05/11/2026|Cobro comprobante F001-00010||500.00|0.00||1', $dp),
            $lines[0]
        );
        $this->assertSame(
            sprintf('20261100|TM%010d|M1|101|||PEN|00||TM%010d|10/11/2026||10/11/2026|Movilidad local||0.00|45.00||1', $movement->id, $movement->id),
            $lines[1]
        );
        foreach ($lines as $line) {
            $this->assertCount(19, explode('|', $line));
        }
    }

    public function test_format_12_lines_match_the_15_field_layout_and_debe_haber_are_exclusive(): void
    {
        $dp = $this->bankDocumentPayment('F001', '00020', 1000.00, '2026-11-06', '003', 'OP123');
        $fee = $this->bankMovement('2026-11-10', 12.50, -1, 'Comision mantenimiento');

        $result = $this->exporter()->buildOrFail('2026-11', $this->adminScope());
        $file = $this->file($result, '1.2');
        $lines = $this->lines($file);

        $this->assertSame('LE2055555555120261100010200001111.TXT', $file['filename']);
        $this->assertCount(2, $lines);
        $this->assertSame(
            sprintf('20261100|DP%010d|M1|02|191-001|06/11/2026|003|Cobro comprobante F001-00020|6|%s|Cliente Alfa SAC|OP123|1000.00|0.00|1', $dp, self::CUSTOMER_RUC),
            $lines[0]
        );
        $this->assertSame(
            sprintf('20261100|TM%010d|M1|02|191-001|10/11/2026|003|Comision mantenimiento|-|-|BCP|TRX9|0.00|12.50|1', $fee->id),
            $lines[1]
        );

        foreach ($lines as $line) {
            $fields = explode('|', $line);
            $this->assertCount(15, $fields);
            $debit = (float) $fields[12];
            $credit = (float) $fields[13];
            $this->assertTrue(($debit > 0) xor ($credit > 0), 'Debe and haber are mutually exclusive and never both 0.00');
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $fields[12]);
        }
    }

    public function test_invalid_counterparty_identity_becomes_the_allowed_empty_value(): void
    {
        $this->db->table('persons')->where('id', 1)->update(['number' => '20100070971']); // bad modulo 11
        $this->bankDocumentPayment('F001', '00021', 100.00, '2026-11-06', '003');

        $lines = $this->lines($this->file($this->exporter()->buildOrFail('2026-11', $this->adminScope()), '1.2'));
        $fields = explode('|', $lines[0]);

        $this->assertSame('-', $fields[8]);
        $this->assertSame('-', $fields[9]);
        $this->assertSame('Cliente Alfa SAC', $fields[10]);
    }

    public function test_cuo_is_stable_across_runs_even_when_earlier_movements_are_added(): void
    {
        $dp = $this->bankDocumentPayment('F001', '00030', 300.00, '2026-11-15', '003');

        $first = $this->lines($this->file($this->exporter()->buildOrFail('2026-11', $this->adminScope()), '1.2'));
        $this->assertStringStartsWith(sprintf('20261100|DP%010d|M1|', $dp), $first[0]);

        // A movement dated BEFORE the existing one is recorded later: the report order (and its correlative) shifts,
        // the CUO of the existing row does not.
        $earlier = $this->bankMovement('2026-11-02', 20.00, -1, 'Comision tardia');
        $second = $this->lines($this->file($this->exporter()->buildOrFail('2026-11', $this->adminScope()), '1.2'));

        $this->assertCount(2, $second);
        $this->assertStringStartsWith(sprintf('20261100|TM%010d|M1|', $earlier->id), $second[0]);
        $this->assertStringStartsWith(sprintf('20261100|DP%010d|M1|', $dp), $second[1]);
        $this->assertSame($first[0], $second[1]);
        $this->assertSame($this->exporter()->buildOrFail('2026-11', $this->adminScope())['files'], $this->exporter()->buildOrFail('2026-11', $this->adminScope())['files']);
    }

    public function test_cuo_helper_uses_source_code_and_ten_digit_id_within_forty_chars(): void
    {
        $this->assertSame('DP0000000123', PleCashBookExporter::cuo(DocumentPayment::class, 123));
        $this->assertSame('TM0000000007', PleCashBookExporter::cuo(TreasuryMovement::class, '7'));
        $this->assertSame('OT0000000001', PleCashBookExporter::cuo('Unknown\\Model', 1));
        $this->assertLessThanOrEqual(40, strlen(PleCashBookExporter::cuo(DocumentPayment::class, PHP_INT_MAX)));
    }

    public function test_export_refuses_when_a_bank_account_with_movements_has_no_table_3_code(): void
    {
        $this->db->table('bank_accounts')->where('id', 1)->update(['sunat_financial_entity_code' => null]);
        $this->bankDocumentPayment('F001', '00040', 100.00, '2026-11-06', '003');

        $preview = $this->exporter()->build('2026-11', $this->adminScope());
        $this->assertCount(1, $preview['blockers']);
        $this->assertSame('missing_financial_entity_code', $preview['blockers'][0]['code']);
        $this->assertSame(['BCP PEN (191-001)'], $preview['blockers'][0]['items']);

        try {
            $this->exporter()->buildOrFail('2026-11', $this->adminScope());
            $this->fail('The export must be refused.');
        } catch (PleExportException $e) {
            $this->assertStringContainsString('Tabla 3', $e->getMessage());
        }
    }

    public function test_accounts_without_movements_do_not_block_the_export(): void
    {
        $this->bankDocumentPayment('F001', '00041', 100.00, '2026-11-06', '003'); // account 1 has the code

        // Accounts 2 and 4 have no code but no movement in the period.
        $result = $this->exporter()->buildOrFail('2026-11', $this->adminScope());

        $this->assertSame([], $result['blockers']);
    }

    public function test_export_refuses_rows_without_table_1_code_and_lists_them(): void
    {
        $this->bankDocumentPayment('F001', '00050', 100.00, '2026-11-06', null);
        $this->bankMovement('2026-11-07', 5.00, -1, 'Comision sin codigo', null);

        $preview = $this->exporter()->build('2026-11', $this->adminScope());
        $blocker = $preview['blockers'][0];

        $this->assertSame('missing_payment_code', $blocker['code']);
        $this->assertCount(2, $blocker['items']);
        $this->assertStringContainsString('Cobro comprobante F001-00050', $blocker['items'][0]);
        $this->assertStringContainsString('2026-11-07', $blocker['items'][1]);

        $this->expectException(PleExportException::class);
        $this->exporter()->buildOrFail('2026-11', $this->adminScope());
    }

    public function test_text_fields_are_sanitized_and_never_break_the_field_count(): void
    {
        $this->bankMovement('2026-11-08', 9.00, -1, "Pago | a/b \\ c\r\nCancelación de Núñez " . str_repeat('x', 300));

        $line = $this->lines($this->file($this->exporter()->buildOrFail('2026-11', $this->adminScope()), '1.2'))[0];
        $fields = explode('|', $line);

        $this->assertCount(15, $fields);
        $this->assertStringStartsWith('Pago a b c Cancelacion de Nunez xxx', $fields[7]);
        $this->assertLessThanOrEqual(200, strlen($fields[7]));
        $this->assertStringNotContainsString("\n", $line);
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $line);
    }

    public function test_trailing_pipe_is_configurable_and_off_by_default(): void
    {
        $this->bankMovement('2026-11-08', 9.00, -1, 'Comision');

        $default = $this->lines($this->file($this->exporter()->buildOrFail('2026-11', $this->adminScope()), '1.2'))[0];
        $this->assertStringEndsWith('|1', $default);

        $this->app['config']->set('treasury.ple.trailing_pipe', true);
        $withPipe = $this->lines($this->file($this->exporter()->buildOrFail('2026-11', $this->adminScope()), '1.2'))[0];
        $this->assertSame($default . '|', $withPipe);
    }

    public function test_every_record_ends_with_crlf_and_the_file_has_no_bom(): void
    {
        $this->bankMovement('2026-11-08', 9.00, -1, 'Comision');
        $this->bankMovement('2026-11-09', 4.00, -1, 'Comision 2');

        $content = $this->file($this->exporter()->buildOrFail('2026-11', $this->adminScope()), '1.2')['content'];

        $this->assertStringEndsWith("\r\n", $content);
        $this->assertSame(2, substr_count($content, "\r\n"));
        $this->assertSame(0, substr_count(str_replace("\r\n", '', $content), "\n"));
        $this->assertStringStartsWith('20261100|', $content);
    }

    public function test_usd_accounts_get_their_own_file_with_currency_indicator_two(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 8, 'bank_id' => 1, 'description' => 'BCP USD', 'number' => '191-USD', 'currency_type_id' => 'USD', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 1,
            'sunat_financial_entity_code' => '02',
        ]);
        $this->bankMovement('2026-11-12', 50.00, -1, 'Wire fee', '003', 'W1', 8, 'USD');
        $this->bankMovement('2026-11-12', 15.00, -1, 'Comision PEN');

        $result = $this->exporter()->buildOrFail('2026-11', $this->adminScope());

        $this->assertCount(3, $result['files']);
        $usd = $this->file($result, '1.2', 'USD');
        $this->assertSame('LE2055555555120261100010200001121.TXT', $usd['filename']);
        $this->assertStringContainsString('|02|191-USD|12/11/2026|003|Wire fee|', $usd['content']);
        $this->assertStringContainsString('|0.00|50.00|1', $usd['content']);
        $this->assertStringNotContainsString('Wire fee', $this->file($result, '1.2', 'PEN')['content']);
    }

    public function test_non_admin_only_exports_rows_of_accounts_in_their_scope(): void
    {
        $this->bankDocumentPayment('F001', '00060', 100.00, '2026-11-06', '003'); // account 1 (establishment 1)

        $other = $this->exporter()->buildOrFail('2026-11', ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 2]);
        $this->assertFalse($this->file($other, '1.2')['has_info']);

        $own = $this->exporter()->buildOrFail('2026-11', ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1]);
        $this->assertTrue($this->file($own, '1.2')['has_info']);
    }

    public function test_non_admin_without_establishment_is_refused(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->exporter()->build('2026-11', ['is_admin' => false, 'user_id' => 2, 'establishment_id' => null]);
    }

    public function test_period_must_be_a_valid_month(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->exporter()->build('2026-13', $this->adminScope());
    }

    public function test_invalid_company_ruc_blocks_the_export(): void
    {
        $this->db->table('companies')->where('id', 1)->update(['number' => '123']);

        $result = $this->exporter()->build('2026-11', $this->adminScope());

        $this->assertSame('invalid_ruc', $result['blockers'][0]['code']);
    }
}
