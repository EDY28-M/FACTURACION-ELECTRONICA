<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Sunat\BoletaIdentification;
use PHPUnit\Framework\TestCase;

class BoletaIdentificationTest extends TestCase
{
    public function test_threshold_is_strictly_greater_than_700(): void
    {
        $this->assertFalse(BoletaIdentification::requiresIdentification(699.99, 'PEN', 1));
        $this->assertFalse(BoletaIdentification::requiresIdentification(700.00, 'PEN', 1));
        $this->assertTrue(BoletaIdentification::requiresIdentification(700.01, 'PEN', 1));
        $this->assertFalse(BoletaIdentification::requiresIdentification('700.004', 'PEN', 1));
    }

    public function test_foreign_currency_is_converted_to_soles(): void
    {
        // USD 200 * 3.50 = S/ 700.00 -> not required; USD 200.01 * 3.50 = 700.035 -> required.
        $this->assertFalse(BoletaIdentification::requiresIdentification(200, 'USD', 3.5));
        $this->assertTrue(BoletaIdentification::requiresIdentification(200.01, 'USD', 3.5));
        $this->assertTrue(BoletaIdentification::requiresIdentification(250, 'USD', 3.7));
    }

    public function test_generic_customer_is_not_identified(): void
    {
        $this->assertFalse(BoletaIdentification::isIdentified('0', '99999999'));
        $this->assertFalse(BoletaIdentification::isIdentified('-', ''));
        $this->assertFalse(BoletaIdentification::isIdentified('', null));
        $this->assertFalse(BoletaIdentification::isIdentified('1', '00000000'));
        $this->assertFalse(BoletaIdentification::isIdentified('1', ''));
        $this->assertTrue(BoletaIdentification::isIdentified('1', '45871236'));
        $this->assertTrue(BoletaIdentification::isIdentified('6', '20567138462'));
        $this->assertTrue(BoletaIdentification::isIdentified('4', '001234567'));
    }

    public function test_assert_throws_only_above_threshold_without_identification(): void
    {
        BoletaIdentification::assert(700.00, 'PEN', 1, '0', '99999999');
        BoletaIdentification::assert(700.01, 'PEN', 1, '1', '45871236');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('más de S/ 700.00');
        BoletaIdentification::assert(700.01, 'PEN', 1, '0', '99999999');
    }
}
