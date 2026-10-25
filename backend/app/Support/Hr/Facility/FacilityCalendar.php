<?php

namespace App\Support\Hr\Facility;

use Carbon\CarbonImmutable;

/**
 * The business calendar of Deep Cleaning (and of the facility inspection date check): Africa/Mogadishu, UTC+3 all year.
 *
 * The application itself stays on UTC (config/app.php) and database timestamps stay UTC instants. This class answers only calendar questions -
 * "what date is it in Mogadishu?", "what month is it?", "where does a month start and end?" - always with the zone named explicitly, so the result
 * never depends on the server's, the container's or the developer machine's default timezone.
 *
 * Two kinds of value are kept apart:
 *  - DATE values (a completion date, an inspection date) are calendar days. They are compared and shown as 'YYYY-MM-DD' and are never converted
 *    through UTC, so they cannot slide into the previous or next day.
 *  - TIMESTAMPS (a facility's created_at) are UTC instants. To ask "did it exist by the end of the Mogadishu month?" use monthEndExclusiveUtc():
 *    the first instant after the month, written in UTC, to compare against the UTC column.
 *
 * It is a constant, not the editable Settings > Localization timezone, so an administrator's edit can never move a month boundary. The browser
 * mirrors it in web-panel/src/utils/facility.ts (FACILITY_TIME_ZONE).
 */
final class FacilityCalendar
{
    public const TIMEZONE = 'Africa/Mogadishu';

    /** The current moment, as Mogadishu wall-clock time. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE);
    }

    /** Today's calendar date in Mogadishu (midnight, in that zone: use it as a date, not as an instant). */
    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** The current Mogadishu month as 'YYYY-MM'. */
    public static function currentMonth(): string
    {
        return self::now()->format('Y-m');
    }

    public static function isMonthKey(?string $value): bool
    {
        return $value !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1;
    }

    /** The first day of a 'YYYY-MM' month, as a Mogadishu calendar date. */
    public static function monthStart(string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month, self::TIMEZONE)->startOfMonth();
    }

    /** A calendar date 'YYYY-MM-DD' read as a Mogadishu calendar date (never shifted). */
    public static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay();
    }

    /**
     * The first instant after the Mogadishu month that contains $month, as a UTC 'Y-m-d H:i:s' string. A UTC timestamp strictly before it
     * happened during or before that month in Mogadishu (e.g. October ends at 2026-10-31 21:00:00 UTC).
     */
    public static function monthEndExclusiveUtc(CarbonImmutable $month): string
    {
        return $month->setTimezone(self::TIMEZONE)->startOfMonth()->addMonth()->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
