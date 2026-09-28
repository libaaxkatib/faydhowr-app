<?php

namespace App\Support\Hr\ExcelMigration;

use Carbon\Carbon;

/**
 * CONFIRMED business decision: for a REGISTRATION row whose own Date cell
 * genuinely cannot be parsed (even after DateParser's format extensions),
 * infer the closest reliable date from the surrounding registration sequence
 * — but ONLY when the evidence is unambiguous: the nearest successfully-
 * parsed date immediately BEFORE this row and the nearest successfully-
 * parsed date immediately AFTER this row must be the exact same calendar
 * date. If they disagree, or either side has no valid date within the
 * search radius, this deliberately returns null rather than guess — the
 * caller must leave application_date null and report the case, never
 * fabricate one from a single-sided or conflicting signal.
 */
final class RegistrationDateInferrer
{
    private const int SEARCH_RADIUS = 10;

    /**
     * @param  array<int, ?string>  $rawDatesByRow  REGISTRATION row number => raw Date cell value (or null), for every row in the sheet
     * @return array{date: Carbon, beforeRow: int, afterRow: int}|null
     */
    public static function infer(int $targetRow, array $rawDatesByRow): ?array
    {
        $before = self::nearestValidDate($targetRow, $rawDatesByRow, -1);
        $after = self::nearestValidDate($targetRow, $rawDatesByRow, 1);

        if ($before === null || $after === null) {
            return null;
        }

        if (! $before['date']->isSameDay($after['date'])) {
            return null;
        }

        return [
            'date' => $before['date'],
            'beforeRow' => $before['row'],
            'afterRow' => $after['row'],
        ];
    }

    /**
     * @param  array<int, ?string>  $rawDatesByRow
     * @return array{date: Carbon, row: int}|null
     */
    private static function nearestValidDate(int $targetRow, array $rawDatesByRow, int $direction): ?array
    {
        for ($offset = 1; $offset <= self::SEARCH_RADIUS; $offset++) {
            $row = $targetRow + ($direction * $offset);

            if (! array_key_exists($row, $rawDatesByRow)) {
                continue;
            }

            $parsed = DateParser::parse($rawDatesByRow[$row]);

            if ($parsed !== null) {
                return ['date' => $parsed, 'row' => $row];
            }
        }

        return null;
    }
}
