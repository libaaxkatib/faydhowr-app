<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * Decides whether two names recorded against the same phone number plausibly
 * describe the same person (same first name token, or close overall
 * similarity) versus two different people who happen to share a phone
 * number. Deliberately conservative: anything not clearly compatible is
 * reported as a phone conflict rather than merged.
 */
final class NameMatcher
{
    /**
     * For names that ALSO share a phone number — the phone is the strong
     * corroborating signal here, so a shared first name (common in this
     * population) is enough to call them compatible rather than a conflict.
     */
    public static function areCompatible(string $a, string $b): bool
    {
        $normalizedA = self::normalize($a);
        $normalizedB = self::normalize($b);

        if ($normalizedA === $normalizedB) {
            return true;
        }

        $firstA = explode(' ', $normalizedA)[0] ?? '';
        $firstB = explode(' ', $normalizedB)[0] ?? '';

        if ($firstA !== '' && $firstA === $firstB) {
            return true;
        }

        similar_text($normalizedA, $normalizedB, $percent);

        return $percent >= 70.0;
    }

    /**
     * For names with NO other corroborating signal (e.g. cross-referencing
     * MOGADISHU HOSPITAL's names, which have no phone, against the rest of
     * the workbook). A shared first name alone is common and meaningless
     * here (many hundreds of people share "Farxiyo"/"Sahro"/etc.), so this
     * requires the full name to be near-identical.
     */
    public static function isLikelySamePersonByNameAlone(string $a, string $b): bool
    {
        $normalizedA = self::normalize($a);
        $normalizedB = self::normalize($b);

        if ($normalizedA === $normalizedB) {
            return true;
        }

        similar_text($normalizedA, $normalizedB, $percent);

        return $percent >= 90.0;
    }

    private static function normalize(string $name): string
    {
        $lower = mb_strtolower(trim($name));

        return (string) preg_replace('/\s+/', ' ', $lower);
    }
}
