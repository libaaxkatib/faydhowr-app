<?php

namespace App\Support\Hr\Payroll;

use InvalidArgumentException;

/**
 * All payroll arithmetic is done in integer USD cents so results are exact
 * and reproducible. The single rounding rule used everywhere is "round half
 * up (away from zero for negatives) to the nearest cent, once per payroll
 * line" - never rounding an intermediate daily rate first.
 */
final class PayrollMoney
{
    public const CURRENCY = 'USD';

    /**
     * numerator / denominator rounded half-up to a whole number.
     */
    public static function roundDiv(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }

        if ($numerator < 0) {
            return -self::roundDiv(-$numerator, $denominator);
        }

        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }

    /**
     * @param  int|float|string  $dollars  e.g. 300, "300.50"
     */
    public static function toCents(int|float|string $dollars): int
    {
        if (! is_numeric($dollars)) {
            throw new InvalidArgumentException('Amount must be numeric.');
        }

        return (int) round(((float) $dollars) * 100);
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
