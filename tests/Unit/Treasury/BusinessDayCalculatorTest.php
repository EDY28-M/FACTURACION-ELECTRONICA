<?php

namespace Tests\Unit\Treasury;

use Modules\Treasury\Services\DetractionService;
use Modules\Treasury\Support\BusinessDayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Deadline for the buyer's detraction deposit: 5th business day of the month after the Registro de Compras entry
 * (docs/plans/tesoreria-investigacion-sunat.md section 2). Weekends are excluded; holidays are injected data.
 */
class BusinessDayCalculatorTest extends TestCase
{
    public function test_fifth_business_day_skips_weekends(): void
    {
        $calc = new BusinessDayCalculator([]);

        // November 2026: Sun 1, Mon 2, Tue 3, Wed 4, Thu 5, Fri 6 -> 5th business day is Fri 6.
        $this->assertSame('2026-11-06', $calc->nthBusinessDayOfNextMonth('2026-10-15', 5));
        // The month of the entry does not matter, only the following month.
        $this->assertSame('2026-11-06', $calc->nthBusinessDayOfNextMonth('2026-10-01', 5));
        $this->assertSame('2026-11-06', $calc->nthBusinessDayOfNextMonth('2026-10-31', 5));
    }

    public function test_year_rollover_from_december_to_january(): void
    {
        $calc = new BusinessDayCalculator([]);

        // January 2027: Fri 1 (no holiday configured), Mon 4, Tue 5, Wed 6, Thu 7 -> 5th is Thu 7.
        $this->assertSame('2027-01-07', $calc->nthBusinessDayOfNextMonth('2026-12-10', 5));
    }

    public function test_holidays_are_data_driven_and_shift_the_deadline(): void
    {
        $withHoliday = new BusinessDayCalculator(['2027-01-01']);
        // Jan 1 is not a business day: Mon 4, Tue 5, Wed 6, Thu 7, Fri 8 -> 5th is Fri 8.
        $this->assertSame('2027-01-08', $withHoliday->nthBusinessDayOfNextMonth('2026-12-10', 5));

        // A holiday given through a callable provider behaves the same; invalid entries are ignored.
        $provider = new BusinessDayCalculator(function () {
            return ['2027-01-01', 'not-a-date', ''];
        });
        $this->assertSame('2027-01-08', $provider->nthBusinessDayOfNextMonth('2026-12-10', 5));
        $this->assertFalse($provider->isBusinessDay('2027-01-01'));
        $this->assertFalse($provider->isBusinessDay('2027-01-02')); // Saturday
        $this->assertTrue($provider->isBusinessDay('2027-01-04'));
    }

    public function test_month_ending_on_a_weekend_and_leap_february(): void
    {
        $calc = new BusinessDayCalculator([]);

        // February 2028 (leap year): Tue 1, Wed 2, Thu 3, Fri 4, Mon 7 -> 5th business day is Mon 7.
        $this->assertSame('2028-02-07', $calc->nthBusinessDayOfNextMonth('2028-01-31', 5));
    }

    public function test_payable_due_date_is_the_earlier_of_first_payment_and_fifth_business_day(): void
    {
        $service = (new \ReflectionClass(DetractionService::class))->newInstanceWithoutConstructor();
        $ref = new \ReflectionProperty(DetractionService::class, 'calendar');
        $ref->setAccessible(true);
        $ref->setValue($service, new BusinessDayCalculator([]));
        $repoRef = new \ReflectionProperty(DetractionService::class, 'taxRateRepository');
        $repoRef->setAccessible(true);
        // The deposit business day is regulatory data served by treasury_tax_rates; stubbed here (no database).
        $repoRef->setValue($service, new class extends \Modules\Treasury\Services\TaxRateRepository {
            public function getDetractionDepositBusinessDay(string $date): ?int
            {
                return 5;
            }
        });
        $cacheRef = new \ReflectionProperty(DetractionService::class, 'depositDayCache');
        $cacheRef->setAccessible(true);
        $cacheRef->setValue($service, []);

        // Registry in October -> deadline Fri 2026-11-06.
        $this->assertSame('2026-11-06', $service->computePayableDueDate('2026-10-20'));
        // A payment before the deadline pulls it forward.
        $this->assertSame('2026-11-03', $service->computePayableDueDate('2026-10-20', '2026-11-03'));
        // A payment after the deadline does not push it back.
        $this->assertSame('2026-11-06', $service->computePayableDueDate('2026-10-20', '2026-11-20'));
        // A payment in the same month as the entry is earlier than the deadline.
        $this->assertSame('2026-10-25', $service->computePayableDueDate('2026-10-20', '2026-10-25'));
    }

    public function test_add_business_days_counts_after_the_start_date_skipping_weekends_and_holidays(): void
    {
        $calc = new BusinessDayCalculator(['2026-12-08']);

        // Friday + 1 business day = Monday; the start date itself is never counted.
        $this->assertSame('2026-11-30', $calc->addBusinessDays('2026-11-27', 1));
        $this->assertSame('2026-11-27', $calc->addBusinessDays('2026-11-27', 0));
        // A Saturday start still counts from the next business day.
        $this->assertSame('2026-11-30', $calc->addBusinessDays('2026-11-28', 1));
        // 15 business days after 2026-11-27: 18 Dec without holidays, 21 Dec with the 8 Dec holiday.
        $this->assertSame('2026-12-21', $calc->addBusinessDays('2026-11-27', 15));
        $this->assertSame('2026-12-18', (new BusinessDayCalculator([]))->addBusinessDays('2026-11-27', 15));
        // Year rollover.
        $this->assertSame('2027-01-04', (new BusinessDayCalculator([]))->addBusinessDays('2026-12-30', 3));
    }
}
