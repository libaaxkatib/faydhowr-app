<?php

namespace App\Support\Hr\Payroll;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Prorated monthly salary by actual calendar days:
 *
 *   gross = monthly salary x eligible calendar days / calendar days in month
 *
 * Employment start and end dates are inclusive. When the salary changes
 * during the month, each eligible day uses the salary in effect on that day
 * and the whole month is rounded once (half-up) at the end.
 */
final class ProrationCalculator
{
    /**
     * @param  string  $period  "YYYY-MM"
     * @param  list<array{0: string, 1: ?string}>  $excludedRanges  inclusive calendar ranges ([from, to|null]) this assignment is NOT paid for because another
     *                                                              assignment (a Replacement) holds the position then - the same position-day is never paid twice
     * @return array{period: string, days_in_month: int, eligible_days: int, gross_cents: int, numerator: int, excluded_days: int, segments: list<array{from: string, to: string, days: int, monthly_cents: int}>}
     */
    public function monthlyGross(string $period, SalaryTimeline $salary, ?string $employedFrom, ?string $employedTo = null, array $excludedRanges = []): array
    {
        [$first, $daysInMonth] = $this->month($period);
        $from = $employedFrom !== null ? new DateTimeImmutable($employedFrom) : null;
        $to = $employedTo !== null ? new DateTimeImmutable($employedTo) : null;

        $numerator = 0;
        $eligibleDays = 0;
        $excludedDays = 0;
        $segments = [];

        for ($offset = 0; $offset < $daysInMonth; $offset++) {
            $day = $first->modify("+{$offset} days");

            if (($from !== null && $day < $from) || ($to !== null && $day > $to)) {
                continue;
            }

            if ($this->isExcluded($day->format('Y-m-d'), $excludedRanges)) {
                $excludedDays++;

                continue;
            }

            $monthly = $salary->monthlyCentsOn($day);

            if ($monthly === null) {
                throw new RuntimeException("No salary is defined for {$day->format('Y-m-d')}; payroll will not guess one.");
            }

            $numerator += $monthly;
            $eligibleDays++;

            $last = array_key_last($segments);
            if ($last !== null && $segments[$last]['monthly_cents'] === $monthly && $segments[$last]['to'] === $day->modify('-1 day')->format('Y-m-d')) {
                $segments[$last]['to'] = $day->format('Y-m-d');
                $segments[$last]['days']++;
            } else {
                $segments[] = ['from' => $day->format('Y-m-d'), 'to' => $day->format('Y-m-d'), 'days' => 1, 'monthly_cents' => $monthly];
            }
        }

        return [
            'period' => $period,
            'days_in_month' => $daysInMonth,
            'eligible_days' => $eligibleDays,
            'gross_cents' => PayrollMoney::roundDiv($numerator, $daysInMonth),
            // The exact (unrounded) amount x days_in_month; payroll lines that share one position use it to reconcile cent remainders.
            'numerator' => $numerator,
            'excluded_days' => $excludedDays,
            'segments' => $segments,
        ];
    }

    /** @param  list<array{0: string, 1: ?string}>  $ranges */
    private function isExcluded(string $iso, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($iso >= $from && ($to === null || $iso <= $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: int}
     */
    public static function month(string $period): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException("Payroll period must look like YYYY-MM, got '{$period}'.");
        }

        $first = new DateTimeImmutable("{$period}-01");

        return [$first, (int) $first->format('t')];
    }
}
