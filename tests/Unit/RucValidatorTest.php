<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Sunat\RucValidator;
use Exception;
use PHPUnit\Framework\TestCase;

class RucValidatorTest extends TestCase
{
    public function test_valid_rucs_pass(): void
    {
        foreach (['20100070970', '20131312955', '10000000014'] as $ruc) {
            $this->assertTrue(RucValidator::isValid($ruc), $ruc);
        }
    }

    public function test_wrong_check_digit_fails(): void
    {
        $this->assertFalse(RucValidator::isValid('20100070971'));
        $this->assertFalse(RucValidator::isValid('20131312950'));
    }

    public function test_remainder_edge_cases_map_to_zero_and_one(): void
    {
        // Build RUCs whose remainder is 0 (check 1) and 1 (check 0).
        $found = [];
        for ($n = 0; $n < 100000 && !isset($found[0], $found[1]); $n++) {
            $base = '20' . str_pad((string) $n, 8, '0', STR_PAD_LEFT);
            $sum = 0;
            foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $w) {
                $sum += (int) $base[$i] * $w;
            }
            $found[$sum % 11] = $base . ((11 - $sum % 11) % 10);
        }
        $this->assertTrue(RucValidator::isValid($found[0]));
        $this->assertTrue(RucValidator::isValid($found[1]));
        $this->assertSame('1', substr($found[0], -1));
        $this->assertSame('0', substr($found[1], -1));
    }

    public function test_format_and_prefix_are_checked(): void
    {
        $this->assertFalse(RucValidator::isValid('2010007097'));
        $this->assertFalse(RucValidator::isValid('201000709701'));
        $this->assertFalse(RucValidator::isValid('2010007097A'));
        $this->assertFalse(RucValidator::isValid(''));
        $this->assertFalse(RucValidator::isValid(null));
        $this->assertFalse(RucValidator::isValid('30100070970'));
    }

    public function test_assert_only_validates_type_6(): void
    {
        RucValidator::assert('1', '12345678');
        RucValidator::assert('0', '99999999');
        RucValidator::assert('6', '20100070970');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('El RUC 20100070971 no es válido (dígito verificador incorrecto).');
        RucValidator::assert('6', '20100070971');
    }
}
