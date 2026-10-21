<?php

namespace App\Rules;

use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Company names are unique ignoring upper/lower case, surrounding whitespace AND spaces inside the name, so "TransitHotel", "TRANSITHOTEL"
 * and "Transit Hotel" can never exist as three companies - which is exactly the trap when a name is corrected from "5GHOTEL" to "5G HOTEL".
 * A name that is already an alias (old spelling) of a DIFFERENT company is refused as well, so one name never points at two companies.
 * The database keeps its own unique index on lower(name) as the last line of defence.
 */
class UniqueCompanyName implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public static function key(?string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', (string) $name) ?? '');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = self::key((string) $value);

        if ($key === '') {
            return;
        }

        // Compared in PHP: SQL cannot strip every kind of whitespace portably, and the table is small (a few hundred rows).
        $sameName = ClientCompany::query()
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))
            ->get(['id', 'name'])
            ->contains(fn (ClientCompany $company): bool => self::key($company->name) === $key);

        if ($sameName) {
            $fail('A company with this name already exists (names are compared ignoring upper/lower case and spaces).');

            return;
        }

        $sameAlias = ClientCompanyAlias::query()
            ->when($this->ignoreId !== null, fn ($query) => $query->where('client_company_id', '!=', $this->ignoreId))
            ->get(['client_company_id', 'alias'])
            ->contains(fn (ClientCompanyAlias $alias): bool => self::key($alias->alias) === $key);

        if ($sameAlias) {
            $fail('This name is already used as another spelling (alias) of a different company.');
        }
    }
}
