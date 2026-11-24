<?php

namespace Tests\Unit\Treasury;

use Modules\Treasury\Support\PleFormatter;
use PHPUnit\Framework\TestCase;

class PleFormatterTest extends TestCase
{
    public function test_text_removes_forbidden_characters_control_chars_and_collapses_whitespace(): void
    {
        $this->assertSame('Pago A B C D', PleFormatter::text("Pago A|B/C\\D", 200));
        $this->assertSame('Linea uno Linea dos', PleFormatter::text("Linea uno\r\n\tLinea   dos", 200));
    }

    public function test_text_transliterates_to_ascii_by_default_and_can_keep_utf8(): void
    {
        $this->assertSame('Cancelacion de Nunez y Cia', PleFormatter::text('Cancelación de Núñez y Cía', 200));
        $this->assertSame('Cancelación de Núñez', PleFormatter::text('Cancelación de Núñez', 200, false));
        // Unknown symbols are dropped in ASCII mode.
        $this->assertSame('Total 10', PleFormatter::text('Total ✓ 10', 200));
    }

    public function test_text_is_truncated_without_trailing_space(): void
    {
        $this->assertSame(str_repeat('a', 10), PleFormatter::text(str_repeat('a', 10) . ' bbbb', 11));
        $this->assertSame(200, strlen(PleFormatter::text(str_repeat('x', 500), 200)));
    }

    public function test_alnum_keeps_only_the_allowed_characters(): void
    {
        $this->assertSame('191-001(2).A,b', PleFormatter::alnum('191-001 (2).A,b', 30));
        $this->assertSame('F00110', PleFormatter::alnum('F001/10 |', 30));
        $this->assertSame('12345', PleFormatter::alnum('1234567890', 5));
    }

    public function test_amount_uses_two_decimals_without_thousands_separator_and_never_negative_zero(): void
    {
        $this->assertSame('1234567.50', PleFormatter::amount(1234567.5));
        $this->assertSame('0.00', PleFormatter::amount(0.0));
        $this->assertSame('0.00', PleFormatter::amount(-0.001));
        $this->assertSame('10.01', PleFormatter::amount(10.005));
    }

    public function test_date_is_day_month_year(): void
    {
        $this->assertSame('05/11/2026', PleFormatter::date('2026-11-05'));
    }

    public function test_document_label_is_split_at_the_last_dash(): void
    {
        $this->assertSame(['NV01', '123'], PleFormatter::splitDocumentLabel('NV01-123'));
        $this->assertSame(['A-B', '9'], PleFormatter::splitDocumentLabel('A-B-9'));
        $this->assertSame(['', '77'], PleFormatter::splitDocumentLabel('77'));
    }

    public function test_ruc_modulo_11_and_identity_document_rules(): void
    {
        $this->assertTrue(PleFormatter::isValidRuc('20100070970'));
        $this->assertFalse(PleFormatter::isValidRuc('20100070971'));
        $this->assertFalse(PleFormatter::isValidRuc('2010007097'));

        $this->assertSame(['6', '20100070970'], PleFormatter::identityDocument('6', '20100070970'));
        $this->assertSame(['-', '-'], PleFormatter::identityDocument('6', '20100070971'));
        $this->assertSame(['1', '12345678'], PleFormatter::identityDocument('1', '12345678'));
        $this->assertSame(['-', '-'], PleFormatter::identityDocument('1', '1234567'));
        $this->assertSame(['7', 'AB123456'], PleFormatter::identityDocument('7', 'AB123456'));
        $this->assertSame(['-', '-'], PleFormatter::identityDocument('4', 'AB-1234567890123'));
        $this->assertSame(['-', '-'], PleFormatter::identityDocument(null, '12345678'));
        $this->assertSame(['-', '-'], PleFormatter::identityDocument('1', '-'));
        $this->assertSame(['-', '-'], PleFormatter::identityDocument('9', '123'));
    }
}
