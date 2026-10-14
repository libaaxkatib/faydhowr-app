<?php

namespace App\Support\Hr;

use App\Models\Position;
use Closure;

/**
 * One rule for "is this the same position name?": surrounding and repeated spaces are ignored and letter case does not
 * matter, so "HR Officer", " hr  officer " and "Hr Officer" are one position. Used by both the create and update
 * requests and by the migration that seeds the Office Staff positions, so duplicates are refused however they are typed.
 */
final class PositionName
{
    public static function normalise(?string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
    }

    public static function key(?string $name): string
    {
        return mb_strtolower(self::normalise($name));
    }

    /** The existing position with this name (any letter case), optionally ignoring one position (the one being edited). */
    public static function existing(?string $name, ?int $ignoreId = null): ?Position
    {
        $key = self::key($name);

        if ($key === '') {
            return null;
        }

        // Compared in PHP, not SQL: SQL TRIM cannot collapse doubled spaces INSIDE an old stored name. The table is tiny.
        return Position::query()
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['id', 'name', 'department_id', 'description', 'created_at', 'updated_at'])
            ->first(fn (Position $position): bool => self::key($position->name) === $key);
    }

    /**
     * Laravel validation rule: fails when another position already has this name.
     *
     * @return Closure(string, mixed, Closure(string): void): void
     */
    public static function mustBeUnique(?int $ignoreId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            if (is_string($value) && self::existing($value, $ignoreId) !== null) {
                $fail('A position with this name already exists.');
            }
        };
    }
}
