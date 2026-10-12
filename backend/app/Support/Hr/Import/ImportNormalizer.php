<?php

namespace App\Support\Hr\Import;

use App\Support\Hr\EmployeeDuplicateFinder;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Normalisation shared by every import so that "the same value" means the same thing everywhere.
 */
final class ImportNormalizer
{
    /**
     * A comparison key for a person's or a company's name: Unicode-normalised where intl is available,
     * lower-cased, every character that is not a letter or digit treated as a separator, and runs of
     * separators collapsed. "  Fartun   Cali-Warsame " and "fartun cali warsame" are the same key.
     * Word ORDER is kept, so reordered names are not treated as equal.
     */
    public static function nameKey(?string $name): string
    {
        $name = (string) $name;

        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name;
        }

        $name = mb_strtolower($name);
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';

        return trim($name);
    }

    /**
     * The system's established phone key (the last nine digits), so "0615123456", "615123456" and
     * "+252 615 123 456" are the same number. Null when there are no digits; callers must treat a key
     * shorter than nine digits as not a full number.
     */
    public static function phoneKey(?string $phone): ?string
    {
        return EmployeeDuplicateFinder::phoneKey($phone);
    }

    public static function isFullPhoneKey(?string $key): bool
    {
        return $key !== null && strlen($key) === 9;
    }

    /**
     * Accepts Excel date serials, ISO (2026-10-01) and day-first dates (01/10/2026, 01-10-2026).
     * Anything else - including an ambiguous format - returns null so the row is reported, never guessed.
     */
    public static function date(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            $serial = (float) $value;

            if ($serial >= 20000 && $serial <= 80000) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($serial))->toDateString();
            }

            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T].*)?$/', $value, $m) === 1) {
            return self::checked((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m) === 1) {
            return self::checked((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private static function checked(int $year, int $month, int $day): ?string
    {
        if ($year < 1990 || $year > 2100 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
