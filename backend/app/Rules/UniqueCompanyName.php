<?php

namespace App\Rules;

use App\Models\ClientCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Company names are unique ignoring upper/lower case and surrounding
 * whitespace, so "TransitHotel" and "TRANSITHOTEL" can never both exist.
 * The database has a matching unique index on lower(name).
 */
class UniqueCompanyName implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = ClientCompany::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            $fail('A company with this name already exists (names are compared ignoring upper/lower case).');
        }
    }
}
