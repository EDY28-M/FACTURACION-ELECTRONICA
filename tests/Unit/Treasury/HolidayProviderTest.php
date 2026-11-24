<?php

namespace Tests\Unit\Treasury;

use Modules\Treasury\Support\BusinessDayCalculator;
use Modules\Treasury\Support\HolidayProvider;
use PHPUnit\Framework\TestCase;

/**
 * Holidays are data (table `treasury_holidays`, config fallback) and feed BusinessDayCalculator. Expected dates are
 * computed by hand from the 2026 calendar.
 */
class HolidayProviderTest extends TestCase
{
    use Phase4Harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function calculator(): BusinessDayCalculator
    {
        return new BusinessDayCalculator(new HolidayProvider());
    }

    public function test_seed_has_the_16_national_holidays_of_2026_and_2027(): void
    {
        foreach (['2026', '2027'] as $year) {
            $rows = $this->db->table('treasury_holidays')
                ->where('is_business_day_override', 0)
                ->where('date', 'like', $year . '-%')
                ->orderBy('date')
                ->pluck('date')->all();

            $this->assertCount(16, $rows, $year);
        }

        $dates2026 = $this->db->table('treasury_holidays')->where('is_business_day_override', 0)->where('date', 'like', '2026-%')->pluck('date')->all();
        foreach (['2026-01-01', '2026-04-02', '2026-04-03', '2026-05-01', '2026-06-07', '2026-06-29', '2026-07-23', '2026-07-28',
            '2026-07-29', '2026-08-06', '2026-08-30', '2026-10-08', '2026-11-01', '2026-12-08', '2026-12-09', '2026-12-25'] as $date) {
            $this->assertContains($date, $dates2026);
        }
        $dates2027 = $this->db->table('treasury_holidays')->where('is_business_day_override', 0)->where('date', 'like', '2027-%')->pluck('date')->all();
        $this->assertContains('2027-03-25', $dates2027);
        $this->assertContains('2027-03-26', $dates2027);
        $this->assertNotContains('2027-04-02', $dates2027);

        // Every holiday cites its legal reference and source.
        $this->assertSame(0, $this->db->table('treasury_holidays')->whereNull('legal_reference')->orWhereNull('source_url')->count());
    }

    public function test_dias_no_laborables_are_stored_but_never_treated_as_holidays(): void
    {
        $overrides = $this->db->table('treasury_holidays')->where('is_business_day_override', 1)->pluck('date')->all();
        $this->assertSame(['2026-01-02', '2026-07-27'], $overrides);

        $provider = new HolidayProvider();
        $this->assertNotContains('2026-01-02', $provider->all());
        $this->assertNotContains('2026-07-27', $provider->all());

        $calc = $this->calculator();
        $this->assertTrue($calc->isBusinessDay('2026-01-02')); // Friday, hábil para fines tributarios
        $this->assertTrue($calc->isBusinessDay('2026-07-27')); // Monday
    }

    public function test_fifth_business_day_of_august_2026_skips_the_6th_of_august(): void
    {
        // August 2026: Sat 1, Sun 2, Mon 3 (1), Tue 4 (2), Wed 5 (3), Thu 6 HOLIDAY, Fri 7 (4), Mon 10 (5).
        $this->assertSame('2026-08-10', $this->calculator()->nthBusinessDayOfNextMonth('2026-07-15', 5));
        // Without the holiday it would be Friday the 7th... plus one: Mon 3, Tue 4, Wed 5, Thu 6, Fri 7.
        $this->assertSame('2026-08-07', (new BusinessDayCalculator([]))->nthBusinessDayOfNextMonth('2026-07-15', 5));
    }

    public function test_fifth_business_day_of_other_2026_and_2027_months(): void
    {
        $calc = $this->calculator();

        // May 2026: Fri 1 holiday, Mon 4 (1), Tue 5 (2), Wed 6 (3), Thu 7 (4), Fri 8 (5).
        $this->assertSame('2026-05-08', $calc->nthBusinessDayOfNextMonth('2026-04-20', 5));
        // April 2026: Wed 1 (1), Thu 2 HOLIDAY, Fri 3 HOLIDAY, Mon 6 (2), Tue 7 (3), Wed 8 (4), Thu 9 (5).
        $this->assertSame('2026-04-09', $calc->nthBusinessDayOfNextMonth('2026-03-31', 5));
        // January 2027: Fri 1 holiday, Mon 4 (1), Tue 5 (2), Wed 6 (3), Thu 7 (4), Fri 8 (5).
        $this->assertSame('2027-01-08', $calc->nthBusinessDayOfNextMonth('2026-12-10', 5));
        // November 2026: Sun 1 holiday, Mon 2 (1), Tue 3 (2), Wed 4 (3), Thu 5 (4), Fri 6 (5).
        $this->assertSame('2026-11-06', $calc->nthBusinessDayOfNextMonth('2026-10-15', 5));
        // April 2027: Thu 1 (1), Fri 2 (2), Mon 5 (3), Tue 6 (4), Wed 7 (5): Semana Santa 2027 was in March.
        $this->assertSame('2027-04-07', $calc->nthBusinessDayOfNextMonth('2027-03-10', 5));
    }

    public function test_year_coverage_follows_the_table_and_estimates_end_for_covered_years(): void
    {
        $calc = $this->calculator();
        $this->assertTrue($calc->hasHolidaysForYear(2026));
        $this->assertTrue($calc->hasHolidaysForYear(2027));
        $this->assertFalse($calc->hasHolidaysForYear(2028));

        // The owner adds the 2028 holidays: the year stops being an estimate.
        $this->db->table('treasury_holidays')->insert(['date' => '2028-01-01', 'name' => 'Año Nuevo', 'scope' => 'national', 'is_business_day_override' => 0]);
        $this->assertTrue($this->calculator()->hasHolidaysForYear(2028));
    }

    public function test_a_year_with_only_dia_no_laborable_rows_is_not_covered(): void
    {
        $this->db->table('treasury_holidays')->insert(['date' => '2029-01-02', 'name' => 'No laborable', 'scope' => 'national', 'is_business_day_override' => 1]);

        $this->assertFalse($this->calculator()->hasHolidaysForYear(2029));
    }

    public function test_config_is_the_fallback_when_the_table_does_not_exist(): void
    {
        $this->db->statement('drop table treasury_holidays');
        $this->app['config']->set('treasury.holidays', ['2028-05-01', 'garbage']);

        $provider = new HolidayProvider();
        $this->assertSame(['2028-05-01'], $provider->all());
        $this->assertTrue((new BusinessDayCalculator($provider))->hasHolidaysForYear(2028));
        $this->assertFalse((new BusinessDayCalculator($provider))->hasHolidaysForYear(2026));
    }

    public function test_config_entries_are_merged_on_top_of_the_table(): void
    {
        $this->app['config']->set('treasury.holidays', ['2026-03-03']);

        $all = (new HolidayProvider())->all();
        $this->assertContains('2026-03-03', $all);
        $this->assertContains('2026-08-06', $all);
        $this->assertSame($all, array_values(array_unique($all)));
    }

    public function test_deadline_on_an_inhabil_day_moves_to_the_next_business_day(): void
    {
        // A holiday on a Friday followed by a weekend pushes the 5th business day to the following Monday/Tuesday.
        $calc = new BusinessDayCalculator(['2026-12-04']); // Friday
        // December 2026: Tue 1 (1), Wed 2 (2), Thu 3 (3), Fri 4 HOLIDAY, Mon 7 (4), Tue 8 (5).
        $this->assertSame('2026-12-08', $calc->nthBusinessDayOfNextMonth('2026-11-10', 5));
    }
}
