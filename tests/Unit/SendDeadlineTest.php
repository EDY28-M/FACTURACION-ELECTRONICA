<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Sunat\SendDeadline;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class SendDeadlineTest extends TestCase
{
    public function test_last_day_is_issue_date_plus_three_days(): void
    {
        $this->assertSame('2026-11-13', SendDeadline::lastDay('2026-11-10')->format('Y-m-d'));
    }

    public function test_boundaries(): void
    {
        $this->assertFalse(SendDeadline::isExpired('2026-11-10', '2026-11-13 10:00:00 America/Lima'));
        $this->assertTrue(SendDeadline::isExpired('2026-11-10', '2026-11-14 00:00:00 America/Lima'));
        $this->assertSame(0, SendDeadline::daysLeft('2026-11-10', '2026-11-13 10:00:00 America/Lima'));
        $this->assertSame(3, SendDeadline::daysLeft('2026-11-10', '2026-11-10 08:00:00 America/Lima'));
        $this->assertSame(-1, SendDeadline::daysLeft('2026-11-10', '2026-11-14 08:00:00 America/Lima'));
    }

    public function test_uses_lima_timezone_near_midnight(): void
    {
        // 2026-11-14 04:30 UTC is still 2026-11-13 23:30 in Lima -> not expired.
        $this->assertFalse(SendDeadline::isExpired('2026-11-10', Carbon::parse('2026-11-14 04:30:00', 'UTC')));
        // 2026-11-14 05:00 UTC is 2026-11-14 00:00 in Lima -> expired.
        $this->assertTrue(SendDeadline::isExpired('2026-11-10', Carbon::parse('2026-11-14 05:00:00', 'UTC')));
    }

    public function test_accepts_datetime_instances(): void
    {
        $this->assertSame('2026-11-13', SendDeadline::lastDay(Carbon::parse('2026-11-10 23:59:00'))->format('Y-m-d'));
    }

    public function test_applies_to(): void
    {
        $this->assertTrue(SendDeadline::appliesTo('01', 'F001'));
        $this->assertTrue(SendDeadline::appliesTo('07', 'FC01', '01'));
        $this->assertTrue(SendDeadline::appliesTo('08', 'FD01'));
        $this->assertFalse(SendDeadline::appliesTo('03', 'B001'));
        $this->assertFalse(SendDeadline::appliesTo('07', 'BC01', '03'));
        $this->assertFalse(SendDeadline::appliesTo('07', 'FC01', '03'));
        $this->assertFalse(SendDeadline::appliesTo('01', '0001')); // contingency series
        $this->assertFalse(SendDeadline::appliesTo('80', 'F001'));
    }

    public function test_accepts_several_date_formats(): void
    {
        foreach (['2026-11-10', '2026-11-10 15:30:00', '2026-11-10T15:30:00-05:00', '10/11/2026'] as $value) {
            $this->assertSame('2026-11-13', SendDeadline::lastDay($value)->format('Y-m-d'), $value);
        }
    }

    public function test_unparseable_dates_do_not_throw(): void
    {
        foreach (['', 'abc', '2026-02-31', '31/31/2026', null] as $value) {
            $this->assertNull(SendDeadline::lastDay($value));
            $this->assertNull(SendDeadline::daysLeft($value));
            $this->assertFalse(SendDeadline::isExpired($value, '2030-01-01'));
            $this->assertIsString(SendDeadline::message($value));
        }
    }
}
