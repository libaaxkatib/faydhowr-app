<?php

namespace Tests\Unit\Support;

use App\Support\Hr\Facility\FacilityCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FacilityCalendar answers calendar questions in Africa/Mogadishu (UTC+3, no daylight saving) from frozen UTC instants. Every assertion is also
 * made with an unrelated default timezone in force, so none of it can pass only because the machine happens to run on UTC or Mogadishu time.
 */
class FacilityCalendarTest extends TestCase
{
    private string $originalZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        date_default_timezone_set($this->originalZone);
        parent::tearDown();
    }

    /** @return array<string, array{string, string, string}> */
    public static function instants(): array
    {
        return [
            'last second of October' => ['2026-10-31 20:59:59', '2026-10-31', '2026-10'],
            'first second of November' => ['2026-10-31 21:00:00', '2026-11-01', '2026-11'],
            '03:00 on 1 November' => ['2026-11-01 00:00:00', '2026-11-01', '2026-11'],
            'last second of 2026' => ['2026-12-31 20:59:59', '2026-12-31', '2026-12'],
            'first second of 2027' => ['2026-12-31 21:00:00', '2027-01-01', '2027-01'],
            'last second of leap-year February' => ['2028-02-29 20:59:59', '2028-02-29', '2028-02'],
            'first second of March after a leap year' => ['2028-02-29 21:00:00', '2028-03-01', '2028-03'],
            'last second of a 28-day February' => ['2027-02-28 20:59:59', '2027-02-28', '2027-02'],
            'first second of March after a 28-day February' => ['2027-02-28 21:00:00', '2027-03-01', '2027-03'],
            'last second of a 30-day month' => ['2026-04-30 20:59:59', '2026-04-30', '2026-04'],
            'first second after a 30-day month' => ['2026-04-30 21:00:00', '2026-05-01', '2026-05'],
        ];
    }

    #[DataProvider('instants')]
    public function test_today_and_the_month_are_read_in_mogadishu_whatever_the_machine_timezone(string $utc, string $day, string $month): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));

        foreach (['UTC', 'Africa/Mogadishu', 'America/New_York', 'Pacific/Auckland', 'Asia/Tokyo'] as $machineZone) {
            date_default_timezone_set($machineZone);

            $this->assertSame($day, FacilityCalendar::today()->toDateString(), "today, machine zone {$machineZone}");
            $this->assertSame($month, FacilityCalendar::currentMonth(), "month, machine zone {$machineZone}");
        }
    }

    public function test_the_zone_is_fixed_and_named(): void
    {
        $this->assertSame('Africa/Mogadishu', FacilityCalendar::TIMEZONE);
        $this->assertSame(10800, CarbonImmutable::parse('2026-06-01 12:00:00', FacilityCalendar::TIMEZONE)->utcOffset() * 60, 'UTC+3');
        $this->assertSame(10800, CarbonImmutable::parse('2026-12-01 12:00:00', FacilityCalendar::TIMEZONE)->utcOffset() * 60, 'no daylight saving');
    }

    public function test_a_month_ends_at_21_00_utc_on_its_last_day_and_the_instant_is_exclusive(): void
    {
        $at = fn (string $month) => FacilityCalendar::monthEndExclusiveUtc(FacilityCalendar::monthStart($month));

        $this->assertSame('2026-10-31 21:00:00', $at('2026-10'));
        $this->assertSame('2026-11-30 21:00:00', $at('2026-11'));
        $this->assertSame('2026-12-31 21:00:00', $at('2026-12'));
        $this->assertSame('2028-02-29 21:00:00', $at('2028-02'), 'leap-year February');
        $this->assertSame('2027-02-28 21:00:00', $at('2027-02'));
        $this->assertSame('2026-04-30 21:00:00', $at('2026-04'));

        // The same answer under any machine timezone.
        date_default_timezone_set('America/New_York');
        $this->assertSame('2026-10-31 21:00:00', $at('2026-10'));
    }

    public function test_date_only_values_keep_their_day_and_month_starts_are_calendar_dates(): void
    {
        foreach (['Pacific/Auckland', 'America/New_York', 'UTC'] as $machineZone) {
            date_default_timezone_set($machineZone);

            foreach (['2026-10-31', '2026-11-01', '2026-12-31', '2027-01-01', '2028-02-29', '2027-03-01'] as $date) {
                $this->assertSame($date, FacilityCalendar::date($date)->toDateString(), "{$date} in {$machineZone}");
                $this->assertSame($date, FacilityCalendar::date($date.' 00:00:00')->toDateString());
            }
            $this->assertSame('2026-11-01', FacilityCalendar::monthStart('2026-11')->toDateString());
            $this->assertSame('2027-01-01', FacilityCalendar::monthStart('2027-01')->toDateString());
            $this->assertSame('2028-02-29', FacilityCalendar::monthStart('2028-02')->endOfMonth()->toDateString());
        }
    }

    public function test_month_keys_are_strict(): void
    {
        foreach (['2026-10', '2027-01', '1999-12'] as $ok) {
            $this->assertTrue(FacilityCalendar::isMonthKey($ok), $ok);
        }
        foreach ([null, '', '2026-13', '2026-00', '2026-1', '26-10', 'October', '2026-10-05', ' 2026-10'] as $bad) {
            $this->assertFalse(FacilityCalendar::isMonthKey($bad), (string) $bad);
        }
    }
}
