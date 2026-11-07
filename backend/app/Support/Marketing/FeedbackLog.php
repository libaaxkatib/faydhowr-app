<?php

namespace App\Support\Marketing;

use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Owner decision E-7 (2026-11-06): feedback on a Marketing record is APPENDED to the feedback already saved. Earlier feedback is never overwritten, edited
 * away or silently deleted. The record keeps one text (`marketing_records.feedback`); a new entry goes after the old text, separated by a blank line.
 * Nothing is dropped from the record's own history either: the follow-up history keeps each entry added through a follow-up, and every change is audited.
 */
final class FeedbackLog
{
    public const SEPARATOR = "\n\n";

    /** The saved text with the new entry after it (the new entry alone when nothing was saved yet). */
    public static function append(?string $existing, string $added): string
    {
        $existing = self::normalise($existing);
        $added = trim(str_replace("\r\n", "\n", $added));

        return $existing === '' ? $added : $existing.self::SEPARATOR.$added;
    }

    /**
     * What to store when a record edit sends a `feedback` value: the same text (nothing changes), or the saved text with more after it. Anything that would
     * change or remove the saved text is refused (422 FEEDBACK_APPEND_ONLY) and nothing is written. Returns null when nothing is to be stored.
     */
    public static function acceptEdit(?string $existing, ?string $incoming): ?string
    {
        $saved = self::normalise($existing);
        $next = self::normalise($incoming);

        if ($next === $saved) {
            return null;
        }

        if ($saved === '' || str_starts_with($next, $saved)) {
            return $next;
        }

        throw new HttpResponseException(ApiResponse::error(
            'Earlier feedback cannot be changed or removed. Add your new feedback after it.',
            'FEEDBACK_APPEND_ONLY',
            422,
            ['feedback' => ['Earlier feedback cannot be changed or removed. Add your new feedback after it.']],
        ));
    }

    /** The part of `$accepted` that comes after the saved text (for the audit entry). */
    public static function addedPart(?string $existing, string $accepted): string
    {
        return trim(mb_substr($accepted, mb_strlen(self::normalise($existing))));
    }

    private static function normalise(?string $text): string
    {
        return trim(str_replace("\r\n", "\n", (string) $text));
    }
}
