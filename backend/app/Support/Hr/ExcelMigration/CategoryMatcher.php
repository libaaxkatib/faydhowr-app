<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * Matches a raw "Job" cell against the EXISTING employee_categories rows
 * (read from the database, never invented here). Keyword-based and explicit
 * — a category is proposed only when a listed keyword is found; anything
 * else is reported as unmatched rather than guessed.
 *
 * Keywords are based on the actual Job-column values observed across the
 * workbook matched against the categories that exist in production:
 * General Cleaning, Cooking, Waiter, Barista, Home Team, and (per a CONFIRMED
 * business decision) Supervisor. A separate, later fallback in
 * ExcelMigrationService — not here — catches anything still unmatched into
 * General Cleaning; this class itself never guesses beyond its keyword list.
 *
 * CONFIRMED BUSINESS RULE (not a guess): "Xaafad"/"Xafad"/"xafad"/"xafaad"
 * (any capitalization/spacing) always means Home Cleaning — mapped here to
 * the existing "Home Team" category, the only existing category that
 * concept fits. This is checked FIRST and deliberately never falls through
 * to "General Cleaning", even though "nadaafad"/"cleaning" are separately
 * (and correctly) mapped there.
 *
 * CONFIRMED BUSINESS RULE (not a guess): "Post Construction" (any spelling/
 * capitalization/format variation) always means General Cleaning — never a
 * new "Post Construction" category. The specific misspellings listed below
 * are the exact variants actually observed in the workbook (not a fuzzy
 * guess at unseen spellings).
 */
final class CategoryMatcher
{
    /**
     * Checked before everything else — see the class docblock. Includes a few
     * further letter-transposed/garbled spellings actually observed in the real
     * Home Cleaning sheet (xadfad, xaadaf, faxad, xaafaad) plus the literal
     * English phrase, all real, repeated variants — not a blind fuzzy guess.
     */
    private const array HOME_CLEANING_KEYWORDS = [
        'xaafad', 'xafad', 'xafaad', 'xadfad', 'xaadaf', 'faxad', 'xaafaad', 'home cleaning',
    ];

    private const string HOME_CLEANING_CATEGORY = 'Home Team';

    /** Checked before the general "General Cleaning" keywords — see the class docblock. */
    private const array POST_CONSTRUCTION_KEYWORDS = [
        'post construction', 'post-construction', 'post constraction', 'post constaraction',
        'post construction worker', 'post costruction', 'post contraction',
        'post constru ction', 'post constuction',
    ];

    /**
     * @var array<string, list<string>> category name => keywords (lowercase). "cunto
     *                                  karin"/"cunta karin" are the real, more common spelling actually observed in
     *                                  the Cooking Centre sheet (8 occurrences) — "karis" alone was silently missing
     *                                  this majority variant. "baarista" is the real double-vowel spelling observed
     *                                  in Waiters Centre (2 occurrences), same pattern as xaafad/xafaad elsewhere.
     */
    private const array KEYWORDS = [
        // CONFIRMED business decision: "Supervisor" is now a real production category
        // (not merely the pre-existing is_supervisor flag) — Job text clearly saying
        // "supervisor" (any casing) maps here, regardless of source sheet.
        'Supervisor' => ['supervisor'],
        'Cooking' => ['chef', 'cook', 'cunta karis', 'cunto karis', 'cunto karin', 'cunta karin', 'kitchen'],
        'Waiter' => ['waiter', 'waitress'],
        // "cleanin"/"cleaninng" are the exact 2 observed typos (missing/extra letter)
        // found in the Waiting List sheet — real, repeated-pattern variants, not a guess.
        'General Cleaning' => ['nadaafad', 'nadafad', 'naadafad', 'cleaning', 'cleaner', 'general cleaning', 'general cleanin', 'general cleaninng'],
        'Barista' => ['barista', 'baarista', 'coffee'],
    ];

    /**
     * @param  list<string>  $existingCategoryNames  the real category names currently in employee_categories
     * @return array{matched: ?string, isConfident: bool}
     */
    public static function match(?string $rawJob, array $existingCategoryNames): array
    {
        $job = mb_strtolower(trim((string) $rawJob));

        if ($job === '') {
            return ['matched' => null, 'isConfident' => false];
        }

        if (in_array(self::HOME_CLEANING_CATEGORY, $existingCategoryNames, true)) {
            foreach (self::HOME_CLEANING_KEYWORDS as $keyword) {
                if (str_contains($job, $keyword)) {
                    return ['matched' => self::HOME_CLEANING_CATEGORY, 'isConfident' => true];
                }
            }
        }

        if (in_array('General Cleaning', $existingCategoryNames, true)) {
            foreach (self::POST_CONSTRUCTION_KEYWORDS as $keyword) {
                if (str_contains($job, $keyword)) {
                    return ['matched' => 'General Cleaning', 'isConfident' => true];
                }
            }
        }

        foreach (self::KEYWORDS as $category => $keywords) {
            if (! in_array($category, $existingCategoryNames, true)) {
                continue; // never propose a category that doesn't actually exist
            }

            foreach ($keywords as $keyword) {
                if (str_contains($job, $keyword)) {
                    return ['matched' => $category, 'isConfident' => true];
                }
            }
        }

        return ['matched' => null, 'isConfident' => false];
    }
}
