<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * CONFIRMED business structure: under the "Cooking" category, there are two
 * selectable specializations:
 *
 *   1. Cook               (pure cooking — "chef"/"cook"/"cunto karin"/etc.
 *                          with no explicit cleaning combination mentioned)
 *   2. Cunto & Nadaafad    (explicitly combined cooking+cleaning duty —
 *                          "nadaafad"/"nadafad" mentioned alongside cooking)
 *
 * Only ever called once CategoryMatcher has already matched the "Cooking"
 * category — this class does not itself decide the category. "Cook" is the
 * safe default for any confirmed-Cooking job text; "Cunto & Nadaafad" is only
 * assigned when the source text explicitly names the cleaning combination —
 * never guessed the other way.
 */
final class CookingSpecializationMatcher
{
    public const string COOK = 'Cook';

    public const string CUNTO_AND_NADAAFAD = 'Cunto & Nadaafad';

    /** @var list<string> */
    private const array NADAAFAD_COMBO_KEYWORDS = ['nadaafad', 'nadafad', 'naadafad'];

    public static function match(?string $rawJob): ?string
    {
        $job = mb_strtolower(trim((string) $rawJob));

        if ($job === '') {
            return null;
        }

        foreach (self::NADAAFAD_COMBO_KEYWORDS as $keyword) {
            if (str_contains($job, $keyword)) {
                return self::CUNTO_AND_NADAAFAD;
            }
        }

        return self::COOK;
    }
}
