<?php

namespace App\Support\Hr;

use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * Shared wording and response for "this record is still in use" refusals (Positions, Departments).
 *
 * The foreign keys that point at these records are ON DELETE SET NULL, so the database itself would let a delete go
 * through and silently blank the reference on every employee, assignment and request. The delete actions therefore
 * count the references first and refuse with this response; nothing is ever cleared, re-pointed or cascaded.
 */
final class ReferenceUsage
{
    /** "3 employees", "1 work assignment" - null when the count is zero. */
    public static function phrase(int $count, string $singular): ?string
    {
        return $count > 0 ? $count.' '.Str::plural($singular, $count) : null;
    }

    /**
     * @param  array<int, string|null>  $phrases  zero-count entries (null) are dropped
     */
    public static function join(array $phrases): ?string
    {
        $parts = array_values(array_filter($phrases));

        if ($parts === []) {
            return null;
        }

        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts).' and '.$last;
    }

    /** 422 with a stable error code and a message that says exactly what still uses the record. */
    public static function refuse(string $noun, string $errorCode, string $usedBy): never
    {
        throw new HttpResponseException(ApiResponse::error(
            "This {$noun} cannot be deleted because it is still used by {$usedBy}. Move or remove those records first.",
            $errorCode,
            422,
        ));
    }
}
