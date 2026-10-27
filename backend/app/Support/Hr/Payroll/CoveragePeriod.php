<?php

namespace App\Support\Hr\Payroll;

use App\Models\TemporaryReplacement;
use App\Support\Hr\Facility\FacilityCalendar;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The days a Temporary cover ACTUALLY existed, and the covered Permanent's monthly salary in effect on each of them
 * (docs/HR_PAYROLL_RULES.md U8, N6, N9-N11). This is the one place that decides which days count; both the Temporary's entitlement
 * (TemporaryExpectedPayment) and the deduction from the Permanent (ReplacementCostService) read it, so they can never use different days.
 *
 *   - The period runs from the cover's actual start date to its actual last day, both INCLUSIVE. An early termination (end_date earlier than
 *     planned_end_date) wins over the plan, and nothing after the actual end is counted.
 *   - It is bounded by the covered assignment's own dates and by `$through` (never later than today in the business calendar: nothing is
 *     charged or paid in advance).
 *   - Verified attendance days play no part: the individually recorded workdays remain a record of attendance and never shrink the period.
 *   - Overlapping covers do not exist: a new or edited cover that overlaps another cover of the same position, or of the same Temporary employee
 *     (unless the positions are on different recorded shifts), is REFUSED when it is saved (CoverConflicts, docs/HR_PAYROLL_RULES.md U12). Nothing
 *     here decides which cover "owns" a day - every cover counts all of its own days.
 *   - The salary is the covered Permanent's monthly salary in effect ON THAT DAY (a change splits a month by its effective date).
 *
 * Every date here is a plain calendar date (UTC midnight): the business day is Mogadishu's, but no instant in another zone is ever compared
 * with a date. Money is the caller's concern; this returns whole cents of monthly salary per day.
 */
final class CoveragePeriod
{
    /**
     * The calendar days of the cover (no salary involved): the actual period, bounded as described above.
     *
     * @return array{start: CarbonImmutable, last: CarbonImmutable, empty: bool, dates: list<string>}
     */
    public static function dates(TemporaryReplacement $replacement, ?CarbonImmutable $through = null): array
    {
        $assignment = $replacement->workAssignment()->firstOrFail();

        $today = CarbonImmutable::parse(FacilityCalendar::today()->toDateString())->startOfDay();
        $through = CarbonImmutable::parse(($through ?? $today)->toDateString())->startOfDay();
        $through = $through->gt($today) ? $today : $through;

        $start = CarbonImmutable::parse($replacement->start_date)->startOfDay();
        $assignmentStart = CarbonImmutable::parse($assignment->start_date)->startOfDay();
        $start = $start->lt($assignmentStart) ? $assignmentStart : $start;

        $last = $through;

        if ($replacement->end_date !== null) {
            $actualEnd = CarbonImmutable::parse($replacement->end_date)->startOfDay();
            $last = $actualEnd->lt($last) ? $actualEnd : $last;
        }

        if ($assignment->end_date !== null) {
            $assignmentEnd = CarbonImmutable::parse($assignment->end_date)->startOfDay();
            $last = $assignmentEnd->lt($last) ? $assignmentEnd : $last;
        }

        $dates = [];

        for ($day = $start; $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return ['start' => $start, 'last' => $last, 'empty' => $last->lt($start), 'dates' => $dates];
    }

    /**
     * The days the cover DECLARES, from its actual start to its actual end (or `$through`), without the covered assignment's bounds. Used only to tell HR about declared days that cannot be counted; never for an amount.
     *
     * @return list<string>
     */
    public static function declaredDates(TemporaryReplacement $replacement, ?CarbonImmutable $through = null): array
    {
        $today = CarbonImmutable::parse(FacilityCalendar::today()->toDateString())->startOfDay();
        $last = CarbonImmutable::parse(($through ?? $today)->toDateString())->startOfDay();
        $last = $last->gt($today) ? $today : $last;

        if ($replacement->end_date !== null) {
            $end = CarbonImmutable::parse($replacement->end_date)->startOfDay();
            $last = $end->lt($last) ? $end : $last;
        }

        $dates = [];

        for ($day = CarbonImmutable::parse($replacement->start_date)->startOfDay(); $day->lte($last); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * The coverage days with the covered Permanent's monthly salary (whole cents) in effect on each of them.
     *
     * @return array{start: CarbonImmutable, last: CarbonImmutable, empty: bool, days: array<string, int>}
     *
     * @throws RuntimeException when a covered day has no Permanent salary (it is never guessed) or the salary cannot be used
     */
    public static function resolve(TemporaryReplacement $replacement, ?CarbonImmutable $through = null): array
    {
        $salary = new SalaryTimeline($replacement->workAssignment()->firstOrFail()->monthlySalaryPeriods());
        $period = self::dates($replacement, $through);
        $days = [];

        foreach ($period['dates'] as $iso) {
            $monthly = $salary->monthlyCentsOn(CarbonImmutable::parse($iso));

            if ($monthly === null) {
                throw new RuntimeException("No permanent salary is defined for {$iso}; the amount will not be guessed.");
            }

            $days[$iso] = $monthly;
        }

        return ['start' => $period['start'], 'last' => $period['last'], 'empty' => $period['empty'], 'days' => $days];
    }
}
