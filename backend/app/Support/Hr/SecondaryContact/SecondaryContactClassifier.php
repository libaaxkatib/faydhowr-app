<?php

namespace App\Support\Hr\SecondaryContact;

/**
 * Issue #1 backfill: reconstructs secondary_contact_name/phone from the
 * existing `notes` text written by the pre-fix ExcelMigrationCommitter
 * ("Secondary/emergency contact: {name} {phone}"). The source workbook is
 * gone, so this deterministically reverses our own write-time format rather
 * than re-parsing Excel — see the migration's own docblock.
 *
 * Distinguishes real contact identifiers from physical/appearance
 * description text that landed in the same source column for some rows
 * (a genuine source-data quality issue, confirmed by manual audit) — see
 * DESCRIPTIVE_KEYWORDS/RELATION_KEYWORDS below. Never invents a name.
 */
final class SecondaryContactClassifier
{
    /** Somali physical/appearance description words — NOT a contact identifier. */
    private const array DESCRIPTIVE_KEYWORDS = [
        'gabar', 'wiil', 'madow', 'caato', 'caata', 'buuran', 'dherer', 'gaaban',
        'miskin', 'qurux', 'nafis', 'mam ', 'mama ', 'maam ', 'marin ah', 'mariin ah',
        'roon', 'wayn', 'ween', 'weyn', ' yar ', 'dhaxdhaxad', 'gaban', 'ilikta',
        'hilib', 'warkeeda', 'isqalafayan', 'grnyaan', 'shaqesa waye', 'da\'weyn',
        'muqato', 'muqata',
    ];

    /** Somali relation/kinship words — a genuine contact identifier. */
    private const array RELATION_KEYWORDS = [
        'aabe', 'aabo', 'hooyo', 'walaal', 'walashed', 'walalked', 'walaashed',
        'habaryar', 'habo', 'adeer', 'eedo', 'abti', 'xaas', 'awo', 'gabadheda',
        'ina adeer', 'ina-adeer',
    ];

    /**
     * @return array{name: ?string, phone: ?string, excluded: bool, reason: string}
     */
    public static function classify(string $notes): array
    {
        if (! preg_match('/Secondary\/emergency contact: (.*?)(?: \| |$)/su', $notes, $m)) {
            return ['name' => null, 'phone' => null, 'excluded' => true, 'reason' => 'no_marker'];
        }

        $segment = trim($m[1]);

        if ($segment === '') {
            return ['name' => null, 'phone' => null, 'excluded' => true, 'reason' => 'empty_segment'];
        }

        if (! preg_match('/^(.*?)\s*(\+?\d{6,12})$/', $segment, $pm)) {
            // Name-only (no trailing phone token).
            return self::classifyNameOnly($segment);
        }

        $name = trim($pm[1]);
        $phone = $pm[2];

        if ($name === '') {
            return ['name' => null, 'phone' => $phone, 'excluded' => false, 'reason' => 'phone_only'];
        }

        if (self::looksDescriptive($name)) {
            // A full descriptive sentence, not a name — keep the phone (still
            // reliably extracted), exclude the name.
            return ['name' => null, 'phone' => $phone, 'excluded' => false, 'reason' => 'name_and_phone_sentence_form'];
        }

        return ['name' => $name, 'phone' => $phone, 'excluded' => false, 'reason' => 'name_and_phone'];
    }

    /**
     * @return array{name: ?string, phone: ?string, excluded: bool, reason: string}
     */
    private static function classifyNameOnly(string $segment): array
    {
        $lower = mb_strtolower($segment);

        foreach (self::RELATION_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return ['name' => $segment, 'phone' => null, 'excluded' => false, 'reason' => 'name_only_relation'];
            }
        }

        if (self::looksDescriptive($segment)) {
            return ['name' => null, 'phone' => null, 'excluded' => true, 'reason' => 'name_only_descriptive'];
        }

        // Neither a clear relation word nor a clear description — ambiguous,
        // excluded rather than guessed.
        return ['name' => null, 'phone' => null, 'excluded' => true, 'reason' => 'name_only_ambiguous'];
    }

    private static function looksDescriptive(string $text): bool
    {
        $lower = ' '.mb_strtolower($text).' ';

        foreach (self::DESCRIPTIVE_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }

        return false;
    }
}
