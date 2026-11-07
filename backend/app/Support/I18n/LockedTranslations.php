<?php

namespace App\Support\I18n;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Owner-approved Somali wording is LOCKED: the 161 messages locked on 2026-10-27, the 86 Release 1C approvals of 2026-11-05 and the two named approved
 * additions. Their approved wording is final; the Super Admin's approval authority over proposals does not extend to replacing it.
 *
 * The built-in catalogue lives in the Web Panel, so the server keeps the list of locked keys in `resources/i18n/locked-translation-keys.json`, generated from the
 * catalogue (review = approved) and pinned to it by a Web Panel test. The server refuses to store an override (the Super Admin editor), to accept a proposal, or to
 * approve one for any of these keys. The panel also ignores any override on them (web-panel/src/i18n/overrides.ts), so nothing a stored row says can change what
 * the screens show. Locking is a boundary around the existing wording: it adds, changes and approves nothing.
 */
final class LockedTranslations
{
    public const CODE = 'TRANSLATION_LOCKED';

    /** @var array<string, true>|null */
    private static ?array $keys = null;

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::set());
    }

    public static function isLocked(string $key): bool
    {
        return isset(self::set()[$key]);
    }

    public static function refusal(string $key): JsonResponse
    {
        return ApiResponse::error(
            "The wording of \"{$key}\" is owner-approved and locked. It cannot be edited, proposed for change or approved here; changing it needs a new decision from the Owner. Nothing was changed.",
            self::CODE,
            422,
        );
    }

    /** @return array<string, true> */
    private static function set(): array
    {
        if (self::$keys === null) {
            $decoded = json_decode((string) file_get_contents(resource_path('i18n/locked-translation-keys.json')), true, 512, JSON_THROW_ON_ERROR);
            self::$keys = array_fill_keys(array_values(array_filter($decoded['keys'] ?? [], 'is_string')), true);
        }

        return self::$keys;
    }
}
