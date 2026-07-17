<?php

namespace App\Support\Accounting;

/**
 * Exact-precision helpers for decimal(18,2) monetary amounts. Amounts are
 * compared and combined as integer cents so no floating point drift can
 * unbalance a journal or a ledger balance.
 */
final class Money
{
    public static function toCents(int|float|string $amount): int
    {
        $normalized = is_string($amount) ? trim($amount) : sprintf('%.2F', $amount);

        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $sign = str_starts_with($normalized, '-') ? -1 : 1;

        return $sign * (abs((int) $whole) * 100 + (int) $fraction);
    }

    public static function fromCents(int $cents): string
    {
        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }
}
