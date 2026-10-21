<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * "Is this the same name?" for small reference tables (departments, workplaces): surrounding and repeated spaces are ignored and letter case
 * does not matter, so "Night Shift", " night  shift " and "NIGHT SHIFT" are one name. The names are compared in PHP (the tables hold a few
 * hundred rows at most), optionally within one scope (for example the workplaces of one company) and ignoring the record being edited.
 */
class UniqueNormalisedName implements ValidationRule
{
    /**
     * @param  array<string, mixed>  $scope  column => value the other rows must share to count (e.g. ['client_company_id' => 3])
     */
    public function __construct(
        private readonly string $table,
        private readonly ?int $ignoreId = null,
        private readonly array $scope = [],
        private readonly string $message = 'This name is already used. Names are the same even if the capital letters or spaces differ.',
    ) {}

    public static function key(?string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = self::key(is_string($value) ? $value : '');

        if ($key === '') {
            return;
        }

        $query = DB::table($this->table);

        foreach ($this->scope as $column => $scoped) {
            $scoped === null ? $query->whereNull($column) : $query->where($column, $scoped);
        }

        if ($this->ignoreId !== null) {
            $query->where('id', '!=', $this->ignoreId);
        }

        if ($query->pluck('name')->contains(fn ($existing): bool => self::key((string) $existing) === $key)) {
            $fail($this->message);
        }
    }
}
