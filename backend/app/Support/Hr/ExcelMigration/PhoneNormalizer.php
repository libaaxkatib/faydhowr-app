<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * Normalizes phone numbers from the historical HR Excel workbook so they can
 * be used as a deduplication key. Somali mobile numbers are 9 digits
 * starting with 6 (e.g. 615123456); cells frequently contain a second number
 * separated by "/", stray spaces, or a leading "0"/"+252".
 */
final class PhoneNormalizer
{
    /**
     * @return array{primary: ?string, alternate: ?string, isValidFormat: bool}
     */
    public static function normalize(?string $raw): array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return ['primary' => null, 'alternate' => null, 'isValidFormat' => false];
        }

        $parts = array_values(array_filter(
            array_map(fn (string $part): string => self::digitsOnly($part), explode('/', $raw)),
            fn (string $part): bool => $part !== '',
        ));

        if ($parts === []) {
            return ['primary' => null, 'alternate' => null, 'isValidFormat' => false];
        }

        $primary = self::stripLeadingCountryPrefix($parts[0]);
        $alternate = isset($parts[1]) ? self::stripLeadingCountryPrefix($parts[1]) : null;

        return [
            'primary' => $primary,
            'alternate' => $alternate,
            'isValidFormat' => self::isSomaliMobileFormat($primary),
        ];
    }

    /**
     * The deduplication key: digits only, country/leading-zero prefix
     * stripped. Two cells like "0615123456" and "615123456" match; "252615123456" also matches.
     */
    public static function dedupeKey(string $normalizedPrimary): string
    {
        return $normalizedPrimary;
    }

    private static function digitsOnly(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    private static function stripLeadingCountryPrefix(string $digits): string
    {
        if (str_starts_with($digits, '252') && strlen($digits) > 9) {
            $digits = substr($digits, 3);
        }

        if (str_starts_with($digits, '0') && strlen($digits) > 9) {
            $digits = ltrim($digits, '0');
        }

        return $digits;
    }

    /**
     * Real Somali mobile operator prefixes (9 digits total), not just "6" —
     * see Issue #8: the old `^6\d{8}$`-only check rejected a large number of
     * genuinely valid numbers. Hormuud=61,77; Somtel=62,65,66; Telesom=63;
     * SomLink=64; SomNet=68; NationLink=69; Amtel=71; Golis=90.
     */
    private static function isSomaliMobileFormat(string $digits): bool
    {
        return (bool) preg_match('/^(?:6[0-9]|71|77|90)\d{7}$/', $digits);
    }
}
