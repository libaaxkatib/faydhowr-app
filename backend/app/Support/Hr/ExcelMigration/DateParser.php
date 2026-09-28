<?php

namespace App\Support\Hr\ExcelMigration;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Parses the date shapes actually observed in the workbook's Date column: an
 * Excel serial number, "DD/MM/YYYY", "DD-MM-YYYY", plus a handful of real,
 * repeated format variants found during the Waiting List/REGISTRATION date
 * audit (never a blind fuzzy guess — each one is a format actually observed
 * multiple times in the real workbook):
 *   - backslash as the date separator ("3\9\2022"), including mixed with a
 *     forward slash in the same value ("21\06/2023")
 *   - a missing separator between month and year ("11/032023" = 11/03/2023)
 *   - a 5-digit year with a redundant zero after "20" ("20023" = "2023")
 * Returns null (never a fabricated date) when the cell is empty or doesn't
 * match one of these exact, reviewed shapes.
 */
final class DateParser
{
    public static function parse(?string $raw): ?Carbon
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((int) $raw));
            } catch (Throwable) {
                return null;
            }
        }

        $normalized = str_replace('\\', '/', $raw);

        foreach (['d/m/Y', 'd-m-Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $normalized);

                if ($parsed !== false) {
                    return $parsed->startOfDay();
                }
            } catch (Throwable) {
                continue;
            }
        }

        // "D/MMYYYY" — separator between month and year is missing but unambiguous:
        // the trailing block is always exactly 6 digits (2-digit month + 4-digit year).
        if (preg_match('#^(\d{1,2})/(\d{2})(\d{4})$#', $normalized, $m) === 1) {
            $parsed = self::tryFormat("{$m[1]}/{$m[2]}/{$m[3]}");

            if ($parsed !== null) {
                return $parsed;
            }
        }

        // A 5-digit year with a redundant zero right after "20" (e.g. "20023" meaning
        // "2023") — observed exactly once, narrow and explicit, not a general
        // fuzzy year-correction rule.
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(20)0(\d{2})$#', $normalized, $m) === 1) {
            $parsed = self::tryFormat("{$m[1]}/{$m[2]}/{$m[3]}{$m[4]}");

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private static function tryFormat(string $value): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('d/m/Y', $value);

            return $parsed !== false ? $parsed->startOfDay() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
