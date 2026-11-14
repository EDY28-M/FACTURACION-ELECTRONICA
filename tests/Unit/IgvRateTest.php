<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Igv\IgvRate;
use PHPUnit\Framework\TestCase;

class IgvRateTest extends TestCase
{
    /**
     * @dataProvider reducedScheduleProvider
     */
    public function test_reduced_regime_schedule(string $date, float $expected)
    {
        $this->assertSame($expected, IgvRate::forDate($date, true));
    }

    public function reducedScheduleProvider(): array
    {
        return [
            'before regime' => ['2022-08-31', 18.00],
            'regime start' => ['2022-09-01', 10.00],
            'end of 10%' => ['2025-12-31', 10.00],
            'start of 10.5%' => ['2026-01-01', 10.50],
            'mid 2026' => ['2026-05-10', 10.50],
            'end of 10.5%' => ['2026-12-31', 10.50],
            'start of 15%' => ['2027-01-01', 15.00],
            'end of 15%' => ['2027-12-31', 15.00],
            'back to general' => ['2028-01-01', 18.00],
        ];
    }

    public function test_regime_off_is_always_general_rate()
    {
        $this->assertSame(18.00, IgvRate::forDate('2026-05-10', false));
        $this->assertSame(18.00, IgvRate::forDate('2027-05-10', false));
    }

    public function test_item_general_rate_overrides_regime()
    {
        $this->assertSame(18.00, IgvRate::forDate('2026-05-10', true, true));
    }

    public function test_from_items_and_format()
    {
        $items = [
            ['affectation_igv_type_id' => '10', 'percentage_igv' => '10.50'],
            ['affectation_igv_type_id' => '20', 'percentage_igv' => '18.00'],
        ];
        $this->assertSame(10.5, IgvRate::fromItems($items));
        $this->assertSame(18.0, IgvRate::fromItems([]));
        $this->assertSame('10.5', IgvRate::format(10.5));
        $this->assertSame('18', IgvRate::format(18));
        $this->assertSame('15', IgvRate::format('15.00'));
    }

    public function test_from_amounts_snaps_to_allowed_rates()
    {
        $this->assertSame(10.5, IgvRate::fromAmounts(null, 9.98, 95.02));
        $this->assertSame(18.0, IgvRate::fromAmounts('', 18.01, 100));
        $this->assertSame(15.0, IgvRate::fromAmounts(null, 15, 100));
        $this->assertNull(IgvRate::fromAmounts(null, 0, 100));
        $this->assertSame('10.50', IgvRate::fromAmounts('10.50', 0, 0));
    }
}
