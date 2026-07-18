<?php

namespace App\Support\Review;

use App\Models\CustomerProfile;

final class ReviewerName
{
    public const ANONYMOUS = 'Verified Customer';

    /**
     * Public reviewer identity per SRS FR-093.6: First Name + Initial
     * (e.g. "Hodan A."); soft-deleted authors display "Verified Customer".
     */
    public static function for(?CustomerProfile $profile): string
    {
        if ($profile === null || $profile->trashed()) {
            return self::ANONYMOUS;
        }

        return self::format((string) $profile->full_name);
    }

    public static function format(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return self::ANONYMOUS;
        }

        $firstName = $parts[0];

        if (count($parts) === 1) {
            return $firstName;
        }

        $lastName = $parts[count($parts) - 1];

        return $firstName.' '.mb_strtoupper(mb_substr($lastName, 0, 1)).'.';
    }
}
