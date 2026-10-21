<?php

namespace App\Support\Hr;

use App\Models\EmployeeCategory;
use Closure;

/**
 * One rule for "is this the same category name?": surrounding and repeated spaces are ignored and letter case does not matter,
 * so "General Cleaning", " general  cleaning " and "GENERAL CLEANING" are one category. Used by the create and update requests so a
 * duplicate is refused however it is typed (the table's own unique index is case-sensitive and would let "cooking" through).
 */
final class EmployeeCategoryName
{
    public static function normalise(?string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
    }

    public static function key(?string $name): string
    {
        return mb_strtolower(self::normalise($name));
    }

    /** The existing category with this name (any letter case), optionally ignoring one category (the one being edited). */
    public static function existing(?string $name, ?int $ignoreId = null): ?EmployeeCategory
    {
        $key = self::key($name);

        if ($key === '') {
            return null;
        }

        // Compared in PHP: SQL TRIM cannot collapse doubled spaces inside an old stored name, and the table is tiny.
        return EmployeeCategory::query()
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['id', 'name', 'description', 'created_at', 'updated_at'])
            ->first(fn (EmployeeCategory $category): bool => self::key($category->name) === $key);
    }

    /**
     * Laravel validation rule: fails when another category already has this name.
     *
     * @return Closure(string, mixed, Closure(string): void): void
     */
    public static function mustBeUnique(?int $ignoreId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            if (is_string($value) && self::existing($value, $ignoreId) !== null) {
                $fail('A category with this name already exists.');
            }
        };
    }
}
