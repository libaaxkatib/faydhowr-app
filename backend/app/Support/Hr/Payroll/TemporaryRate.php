<?php

namespace App\Support\Hr\Payroll;

/**
 * THE definition of what a Temporary replacement is paid (owner decision 2026-11-03):
 *
 *   Temporary daily rate     = the covered Permanent's monthly salary / 30      (always 30: never the real number of days of the month)
 *   Temporary payable amount = daily rate x covered days
 *
 * Example: salary 300 -> 10.00 a day; 12 days -> 120.00, in February, in a 30-day month and in a 31-day month alike.
 *
 * Every figure of a Temporary cover - the expected payment (TemporaryExpectedPayment), the cost deducted from the covered Permanent
 * (ReplacementCostService / ReplacementCostCalculator), the payroll run and the payment list that read them - takes its divisor from here, so they can
 * never disagree. The money rules are unchanged: integer cents, the month's amount rounded ONCE, half-up (PayrollMoney), never a pre-rounded daily rate.
 *
 * It is deliberately NOT used for a Permanent employee's own pay (monthly salary / real days of the month, docs/HR_PAYROLL_RULES.md A2, N11) or for a
 * Replacement-type assignment, which is a Permanent's pay.
 */
final class TemporaryRate
{
    /** The fixed divisor of the Temporary daily rate. */
    public const DIVISOR = 30;

    /**
     * The amount for a month from the sum of "monthly salary in cents" over its covered days (each covered day contributes that day's monthly salary, so a
     * salary change inside the month is honoured day by day). Rounded once, half-up.
     */
    public static function amountCents(int $sumOfMonthlyCentsOverCoveredDays): int
    {
        return PayrollMoney::roundDiv($sumOfMonthlyCentsOverCoveredDays, self::DIVISOR);
    }

    /** The daily rate as shown in a breakdown (four decimals, for display only: no amount is ever calculated from it). */
    public static function dailyRateText(int $monthlySalaryCents): string
    {
        return number_format($monthlySalaryCents / 100 / self::DIVISOR, 4, '.', '');
    }
}
