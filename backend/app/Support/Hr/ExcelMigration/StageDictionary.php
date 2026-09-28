<?php

namespace App\Support\Hr\ExcelMigration;

use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;

/**
 * Reviewed, explicit normalization dictionary for the "Stage" column values
 * actually found in the Fayadhowr HR Excel workbook (see the Phase 5
 * inspection report). Deliberately NOT a fuzzy/regex matcher: every accepted
 * value is listed verbatim so the mapping stays auditable. Anything not in
 * these lists is routed to manual review rather than guessed.
 *
 * Sheet membership is checked first (the strongest signal — e.g. every row
 * in "KUWA LA KANSALEY" is a cancellation regardless of what its Stage cell
 * says), then the cell's own value.
 */
final class StageDictionary
{
    /** @var list<string> */
    private const array NEED_TRAINING_VALUES = [
        'need training', 'need taraining', 'need traninig', 'need traning',
        'need trainnig', 'nedd traning', 'need trainig', 'ned training',
        'need trainng', 'need traiing', 'need trining', 'neee training',
        'need tarining', 'need traing',
    ];

    /** @var list<string> */
    private const array READY_VALUES = [
        'ready', 'ready practical qaadatay',
    ];

    /** @var list<string> */
    private const array STOP_VALUES = [
        'stop',
    ];

    /**
     * Sheets whose membership alone determines status regardless of the Stage cell.
     * Both current and legacy names are listed — whichever sheet actually exists in
     * the loaded workbook is the one that matters; the other is simply absent and a no-op.
     */
    private const array SHEET_DAMIIN_NEEDED = ['NEED DAMIIN', 'DAMIIN WALI KEENIN'];

    private const array SHEET_CANCELLED = ['CANCELED', 'KUWA LA KANSALEY'];

    private const array SHEET_WAITING = ['Waiting List', 'NEW WAITING LIST2026', 'Sheet2'];

    /** Sheets where a blank Stage cell defaults to Applicant/NeedTraining rather than manual review. */
    private const array SHEETS_WITH_TRAINING_DEFAULT = [
        'REGISTRATION', 'Cooking Centre', 'Waiters Centre', 'Home Cleaning',
        'wiilasha', 'Other Jobs', 'Supervisors',
    ];

    public static function resolve(string $sheetName, ?string $rawStage): StageResolution
    {
        $trimmed = trim((string) $rawStage);
        $normalized = mb_strtolower($trimmed);

        if (in_array($sheetName, self::SHEET_DAMIIN_NEEDED, true)) {
            // Guarantor-need is tracked as an INDEPENDENT flag on ResolvedPerson
            // (see ExcelMigrationService::resolveGroup), never as a pipelineStage —
            // a pipelineStage competes by rank against other stages (e.g.
            // NeedTraining) and would silently lose that competition for anyone
            // also found elsewhere with an equal-or-higher-ranked resolution.
            // Confirmed business rule: guarantor-need must never disappear that way.
            return new StageResolution(
                status: EmployeeStatus::Applicant,
                pipelineStage: null,
                isCancellation: false,
                needsManualReview: false,
                reviewReason: null,
                wasDefaulted: false,
                sourceNote: "Sheet: {$sheetName} (guarantor not yet provided — tracked independently, not as a pipeline stage).",
            );
        }

        if (in_array($sheetName, self::SHEET_CANCELLED, true)) {
            return new StageResolution(
                status: EmployeeStatus::Inactive,
                pipelineStage: null,
                isCancellation: true,
                needsManualReview: false,
                reviewReason: null,
                wasDefaulted: false,
                sourceNote: "Sheet: {$sheetName} (cancelled). Original note: \"{$trimmed}\".",
            );
        }

        if (in_array($sheetName, self::SHEET_WAITING, true)) {
            return new StageResolution(
                status: EmployeeStatus::Waiting,
                pipelineStage: null,
                isCancellation: false,
                needsManualReview: false,
                reviewReason: null,
                wasDefaulted: false,
                sourceNote: "Sheet: {$sheetName} (waiting list).",
            );
        }

        if (in_array($normalized, self::STOP_VALUES, true)) {
            return new StageResolution(
                status: EmployeeStatus::Inactive,
                pipelineStage: null,
                isCancellation: true,
                needsManualReview: false,
                reviewReason: null,
                wasDefaulted: false,
                sourceNote: "Stage cell = \"{$trimmed}\" (treated as cancelled).",
            );
        }

        if (in_array($normalized, self::NEED_TRAINING_VALUES, true)) {
            return new StageResolution(
                status: EmployeeStatus::Applicant,
                pipelineStage: EmployeePipelineStage::NeedTraining,
                isCancellation: false,
                needsManualReview: false,
                reviewReason: null,
                wasDefaulted: false,
                sourceNote: "Stage cell = \"{$trimmed}\".",
            );
        }

        if (in_array($normalized, self::READY_VALUES, true)) {
            return new StageResolution(
                status: EmployeeStatus::Waiting,
                pipelineStage: null,
                isCancellation: false,
                needsManualReview: false,
                reviewReason: null,
                wasDefaulted: false,
                sourceNote: "Stage cell = \"{$trimmed}\".",
            );
        }

        if ($normalized === '' && in_array($sheetName, self::SHEETS_WITH_TRAINING_DEFAULT, true)) {
            return new StageResolution(
                status: EmployeeStatus::Applicant,
                pipelineStage: EmployeePipelineStage::NeedTraining,
                isCancellation: false,
                needsManualReview: false,
                reviewReason: null,
                wasDefaulted: true,
                sourceNote: "Stage was blank — defaulted to need_training (sheet default for {$sheetName}).",
            );
        }

        return new StageResolution(
            status: null,
            pipelineStage: null,
            isCancellation: false,
            needsManualReview: true,
            reviewReason: $normalized === ''
                ? 'Stage is blank and this sheet has no default.'
                : "Unrecognized stage value: \"{$trimmed}\".",
            wasDefaulted: false,
            sourceNote: "Stage cell = \"{$trimmed}\".",
        );
    }
}
