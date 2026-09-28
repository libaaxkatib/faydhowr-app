<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * Extracts a genuine cancellation reason from the Excel value that occupies
 * the "reason" position for a cancellation-source row (the trainingFeeCol
 * position on CANCELED/KUWA LA KANSALEY — see
 * SheetConfig::$trainingFeeColIsCancellationReason).
 *
 * CONFIRMED business rule (not a guess): that column is NOT reliably a
 * cancellation reason in practice — inspection of the real workbook showed
 * 69 of 71 CANCELED-sheet rows hold literal training-fee-paid-status noise
 * ("paid"/"piad") in that position, not an explanation. Treating those as
 * "the cancellation reason" would misrepresent fee status as an explanation,
 * which is exactly the kind of invented meaning the business rule forbids.
 * Stage values (Ready/Stop/Need Training/etc.) are ordinary pipeline-progress
 * labels, never a reason, and are deliberately never routed through this class.
 */
final class CancellationReasonExtractor
{
    /** @var list<string> known non-reason tokens observed in the reason-position column */
    private const array NOISE_TOKENS = [
        'paid', 'piad', 'unpaid', 'pending', 'n/a', 'na', 'none', '-', '.', '',
    ];

    /**
     * @return string|null the verbatim (trimmed, original casing) reason text, or null when
     *                     the value is blank, a known noise token, or a bare amount — never guessed
     */
    public static function extract(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);
        $normalized = mb_strtolower($trimmed);

        if (in_array($normalized, self::NOISE_TOKENS, true)) {
            return null;
        }

        $numericCandidate = str_replace([',', ' '], '', $normalized);

        if ($numericCandidate !== '' && is_numeric($numericCandidate)) {
            return null; // a bare amount, not a reason
        }

        return $trimmed;
    }
}
