<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * CONFIRMED business structure: under the "Home Team" (Home Cleaning) category,
 * there are two structured, filterable work types:
 *
 *   Xafad/Xaafad/Xafaad + "jiif"   -> Full Time – Jiif  (live-in)
 *   Xafad/Xaafad/Xafaad + "malin"/"maalin" -> Part Time – Maalin (day)
 *
 * Only ever called once CategoryMatcher has already matched the "Home Team"
 * category — this class does not itself decide the category. Keyword-based
 * and explicit, same convention as CategoryMatcher: every variant listed here
 * was actually observed in the real workbook, never a blind fuzzy guess.
 *
 * CONFIRMED BUSINESS DECISION (explicit, not a guess): every Home Team job
 * text that does NOT clearly and exclusively indicate "malin"/"maalin" (i.e.
 * bare "Xafad" with no qualifier, both "jiif" and "malin" mentioned together,
 * or any other unclear variant) defaults to Full Time – Jiif. This was
 * confirmed by the business owner for the 28 specific real records this
 * applied to at the time — see the migration report for the full list. Only
 * an unambiguous Maalin-only mention still produces Part Time – Maalin.
 */
final class HomeCleaningWorkTypeMatcher
{
    public const string FULL_TIME_JIIF = 'Full Time – Jiif';

    public const string PART_TIME_MAALIN = 'Part Time – Maalin';

    /** @var list<string> */
    private const array JIIF_KEYWORDS = ['jiif', 'jiifa', 'jiid', 'jif'];

    /** @var list<string> Somali-spelling substring keywords — safe as a plain substring check. */
    private const array MAALIN_KEYWORDS = ['malin', 'maalin', 'maliin', 'mlin', 'maamin'];

    /**
     * "day"/"daily" are the English paraphrases actually observed alongside "xafad" in
     * the real sheet (e.g. "xafad day") — but as a bare substring they falsely match
     * inside unrelated Somali words that happen to end in "day" (e.g. "diiday" =
     * "refused", "aaday" = "went/left") — confirmed against 2 real rows that were
     * wrongly flagged ambiguous purely because of this collision. Matched only as a
     * whole word here, never as a substring.
     *
     * @var list<string>
     */
    private const array MAALIN_WHOLE_WORD_KEYWORDS = ['day', 'daily'];

    public static function match(?string $rawJob): ?string
    {
        $job = mb_strtolower(trim((string) $rawJob));

        if ($job === '') {
            return null;
        }

        $isJiif = self::containsAny($job, self::JIIF_KEYWORDS);
        $isMaalin = self::containsAny($job, self::MAALIN_KEYWORDS) || self::containsWholeWord($job, self::MAALIN_WHOLE_WORD_KEYWORDS);

        // CONFIRMED: an unambiguous Maalin-only mention (no Jiif keyword present at all)
        // is the one case that stays Part Time – Maalin. Everything else — Jiif alone,
        // both mentioned together, or neither mentioned — defaults to Full Time – Jiif
        // per the confirmed business decision above.
        if ($isMaalin && ! $isJiif) {
            return self::PART_TIME_MAALIN;
        }

        return self::FULL_TIME_JIIF;
    }

    /**
     * @param  list<string>  $keywords
     */
    private static function containsAny(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keywords
     */
    private static function containsWholeWord(string $haystack, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (preg_match('/(?<![a-z])'.preg_quote($keyword, '/').'(?![a-z])/', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }
}
