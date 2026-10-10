<?php

namespace App\Support\Hr\Payroll;

use DateTimeImmutable;
use RuntimeException;

/**
 * Cost of a Temporary employee covering a Permanent employee's position:
 *
 *   daily replacement rate = permanent monthly salary / calendar days in that month
 *   replacement amount     = daily rate x verified eligible workdays
 *
 * Only days that are verified, fall inside the replacement window
 * (start/end inclusive) and inside the payroll month are charged; a date
 * listed twice is charged once. The amount is computed from the exact
 * fraction and rounded once (half-up), never from a pre-rounded daily rate.
 * The permanent salary applicable on each worked day is used, so a salary
 * change mid-month or a change of month length is handled day by day.
 *
 * A verified day is still NOT charged to the covered employee when they were
 * not payable that day: outside their assignment dates (e.g. after a permanent
 * separation) or already unpaid through approved unpaid leave (charging it
 * again would take the same day's pay twice). Those days are listed in
 * `not_chargeable` so HR can see who bears the cost; they are never silently lost.
 */
final class ReplacementCostCalculator
{
    /**
     * @param  array{replacement_id: int|string, start_date: string, end_date?: ?string, assignment_start?: ?string, assignment_end?: ?string, unpaid_leave_dates?: list<string>, workdays: list<array{date: string, verified: bool}>}  $replacement
     * @return array{replacement_id: int|string, period: string, days_in_month: int, verified_days: int, charged_dates: list<string>, ignored: array{unverified: int, outside_window_or_month: int, duplicates: int}, not_chargeable: array{outside_assignment: list<string>, on_unpaid_leave: list<string>}, amount_cents: int, daily_rate: string}
     */
    public function monthlyCost(string $period, SalaryTimeline $permanentSalary, array $replacement): array
    {
        [$first, $daysInMonth] = ProrationCalculator::month($period);
        $last = $first->modify('+'.($daysInMonth - 1).' days');
        $start = new DateTimeImmutable($replacement['start_date']);
        $end = isset($replacement['end_date']) ? new DateTimeImmutable($replacement['end_date']) : null;
        $assignmentStart = isset($replacement['assignment_start']) ? new DateTimeImmutable($replacement['assignment_start']) : null;
        $assignmentEnd = isset($replacement['assignment_end']) ? new DateTimeImmutable($replacement['assignment_end']) : null;
        $onLeave = array_flip($replacement['unpaid_leave_dates'] ?? []);
        $notChargeable = ['outside_assignment' => [], 'on_unpaid_leave' => []];

        $charged = [];
        $numerator = 0;
        $ignored = ['unverified' => 0, 'outside_window_or_month' => 0, 'duplicates' => 0];
        $rates = [];

        foreach ($replacement['workdays'] as $workday) {
            $day = new DateTimeImmutable($workday['date']);
            $iso = $day->format('Y-m-d');

            if ($day < $first || $day > $last || $day < $start || ($end !== null && $day > $end)) {
                $ignored['outside_window_or_month']++;

                continue;
            }

            if (! $workday['verified']) {
                $ignored['unverified']++;

                continue;
            }

            if (isset($charged[$iso])) {
                $ignored['duplicates']++;

                continue;
            }

            if (($assignmentStart !== null && $day < $assignmentStart) || ($assignmentEnd !== null && $day > $assignmentEnd)) {
                $notChargeable['outside_assignment'][] = $iso;

                continue;
            }

            if (isset($onLeave[$iso])) {
                $notChargeable['on_unpaid_leave'][] = $iso;

                continue;
            }

            $monthly = $permanentSalary->monthlyCentsOn($day);

            if ($monthly === null) {
                throw new RuntimeException("No permanent salary is defined for {$iso}; the replacement cost will not be guessed.");
            }

            $charged[$iso] = $monthly;
            $rates[$monthly] = true;
            $numerator += $monthly;
        }

        ksort($charged);
        $rateText = count($rates) === 1
            ? number_format(array_key_first($rates) / 100 / $daysInMonth, 4, '.', '')
            : (count($rates) === 0 ? '0.0000' : 'varies (salary changed during the month)');

        return [
            'replacement_id' => $replacement['replacement_id'],
            'period' => $period,
            'days_in_month' => $daysInMonth,
            'verified_days' => count($charged),
            'charged_dates' => array_keys($charged),
            'ignored' => $ignored,
            'not_chargeable' => $notChargeable,
            'amount_cents' => PayrollMoney::roundDiv($numerator, $daysInMonth),
            'daily_rate' => $rateText,
        ];
    }
}
