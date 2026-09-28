<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * Explicit, reviewed column layout for one sheet of the HR Excel workbook.
 * Column indexes are 1-based (matching PhpSpreadsheet). Deliberately
 * hardcoded per sheet rather than sniffed from headers at runtime — several
 * sheets have missing/misaligned header rows (see the Phase 5 inspection
 * report), so a dynamic header reader would silently misalign data.
 * `null` means that field does not exist in this sheet.
 */
final readonly class SheetConfig
{
    public function __construct(
        public string $sheetName,
        public int $startRow,
        public ?int $dateCol,
        public int $nameCol,
        public int $phoneCol,
        public ?int $jobCol,
        public ?int $locationCol,
        public ?int $ageCol,
        public ?int $maritalStatusCol,
        public ?int $livesWithCol,
        public ?int $referenceCol,
        public ?int $stageCol,
        public ?int $experienceCol,
        public ?int $otherContactPhoneCol,
        public ?int $otherContactNameCol,
        public ?int $otherInfoCol,
        public ?int $trainingFeeCol,
        public bool $isSupervisorSheet = false,
        /**
         * KUWA LA KANSALEY only: the trainingFeeCol position actually holds
         * the cancellation-reason free text in that sheet, not a fee.
         */
        public bool $trainingFeeColIsCancellationReason = false,
        /**
         * TABABAR MARIN / Student Practical training: the source is too thin
         * or too unresolved to ever land in "ready to import", regardless of
         * what the Stage/date resolve to. Always routed to manual review.
         */
        public bool $forceManualReview = false,
        /**
         * Inclusive last row this config applies to, or null for "through the
         * sheet's highest data row". Only set when a sheet contains more than
         * one differently-laid-out block glued together — see "Waiting List"'s
         * second-block config, built separately in ExcelMigrationService, for
         * the CONFIRMED "NEW WAITING LIST" sub-table starting partway down
         * that sheet with a completely different column layout.
         */
        public ?int $endRow = null,
    ) {}

    /**
     * @return array<string, self>
     */
    public static function registry(): array
    {
        return [
            'REGISTRATION' => new self(
                sheetName: 'REGISTRATION', startRow: 2, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: 13,
                otherInfoCol: 14, trainingFeeCol: 15,
            ),
            'Cooking Centre' => new self(
                sheetName: 'Cooking Centre', startRow: 3, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: null, otherContactPhoneCol: null, otherContactNameCol: null,
                otherInfoCol: 11, trainingFeeCol: null,
            ),
            'Waiters Centre' => new self(
                sheetName: 'Waiters Centre', startRow: 2, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: 13,
                otherInfoCol: 15, trainingFeeCol: null,
            ),
            'Home Cleaning' => new self(
                sheetName: 'Home Cleaning', startRow: 2, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: null, otherContactPhoneCol: null, otherContactNameCol: null,
                otherInfoCol: null, trainingFeeCol: null,
            ),
            'wiilasha' => new self(
                sheetName: 'wiilasha', startRow: 2, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: null, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: null,
                otherInfoCol: null, trainingFeeCol: null,
            ),
            // CONFIRMED: this "Waiting List" sheet is really TWO differently-laid-out blocks
            // glued together — a "NEW WAITING LIST" section marker at row 85 introduces a
            // second block (rows 92+) with a completely different column layout (no Date
            // column at all). This config covers ONLY the first block (rows 2-84); the
            // second block is parsed separately via a dedicated config built in
            // ExcelMigrationService::parseWaitingListSecondBlock() and merged in alongside it.
            'Waiting List' => new self(
                sheetName: 'Waiting List', startRow: 2, endRow: 84, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                // column 10's header is "Ready" but inspection showed its values are experience
                // notes, not a status flag — deliberately not treated as stageCol; the sheet-level
                // default (status=waiting) in StageDictionary drives status for this sheet instead.
                stageCol: null, experienceCol: 10, otherContactPhoneCol: null, otherContactNameCol: null,
                otherInfoCol: null, trainingFeeCol: null,
            ),
            'NEW WAITING LIST2026' => new self(
                sheetName: 'NEW WAITING LIST2026', startRow: 3, dateCol: null, nameCol: 1, phoneCol: 2,
                jobCol: 3, locationCol: 4, ageCol: 5, maritalStatusCol: 6, livesWithCol: 7, referenceCol: 8,
                stageCol: null, experienceCol: 10, otherContactPhoneCol: 11, otherContactNameCol: 12,
                otherInfoCol: 13, trainingFeeCol: 14,
            ),
            'Sheet2' => new self(
                sheetName: 'Sheet2', startRow: 1, dateCol: null, nameCol: 1, phoneCol: 2, jobCol: 3,
                locationCol: 4, ageCol: 5, maritalStatusCol: 6, livesWithCol: 7, referenceCol: 8,
                stageCol: null, experienceCol: 10, otherContactPhoneCol: 11, otherContactNameCol: 12,
                otherInfoCol: 13, trainingFeeCol: 14,
            ),
            // "NEED DAMIIN" (formerly seen under the name "DAMIIN WALI KEENIN" in an earlier
            // workbook export) — people for whom a guarantor is still needed.
            'NEED DAMIIN' => new self(
                sheetName: 'NEED DAMIIN', startRow: 1, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: 13,
                otherInfoCol: 14, trainingFeeCol: 15,
            ),
            // "CANCELED" (formerly seen under the name "KUWA LA KANSALEY" in an earlier
            // workbook export) — explicitly cancelled/withdrew.
            'CANCELED' => new self(
                sheetName: 'CANCELED', startRow: 1, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: 13,
                otherInfoCol: 14, trainingFeeCol: 15, trainingFeeColIsCancellationReason: true,
            ),
            // Legacy sheet names kept for compatibility with the earlier (larger) workbook
            // export — harmless no-ops when the sheet isn't present in the current file.
            'DAMIIN WALI KEENIN' => new self(
                sheetName: 'DAMIIN WALI KEENIN', startRow: 1, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: 13,
                otherInfoCol: 14, trainingFeeCol: 15,
            ),
            'KUWA LA KANSALEY' => new self(
                sheetName: 'KUWA LA KANSALEY', startRow: 1, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: 13,
                otherInfoCol: 14, trainingFeeCol: 15, trainingFeeColIsCancellationReason: true,
            ),
            'Supervisors' => new self(
                sheetName: 'Supervisors', startRow: 2, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: 13,
                otherInfoCol: 14, trainingFeeCol: null, isSupervisorSheet: true,
            ),
            'Other Jobs' => new self(
                sheetName: 'Other Jobs', startRow: 2, dateCol: 1, nameCol: 2, phoneCol: 3, jobCol: 4,
                locationCol: 5, ageCol: 6, maritalStatusCol: 7, livesWithCol: 8, referenceCol: 9,
                stageCol: 10, experienceCol: 11, otherContactPhoneCol: 12, otherContactNameCol: 13,
                // Education (14) and Salary Expectation (15) have no dedicated Employee field —
                // folded into otherInfo/notes rather than invented as new columns.
                otherInfoCol: 14, trainingFeeCol: null,
            ),
            'TABABAR MARIN' => new self(
                sheetName: 'TABABAR MARIN', startRow: 4, dateCol: null, nameCol: 1, phoneCol: 2, jobCol: 3,
                locationCol: 4, ageCol: 5, maritalStatusCol: 6, livesWithCol: 7, referenceCol: 8,
                stageCol: 9, experienceCol: 10, otherContactPhoneCol: 11, otherContactNameCol: 12,
                otherInfoCol: 13, trainingFeeCol: 14, forceManualReview: true,
            ),
            'Student Practical training' => new self(
                sheetName: 'Student Practical training', startRow: 1, dateCol: null, nameCol: 2, phoneCol: 3,
                jobCol: null, locationCol: null, ageCol: null, maritalStatusCol: null, livesWithCol: null,
                referenceCol: null, stageCol: null, experienceCol: null, otherContactPhoneCol: null,
                otherContactNameCol: null, otherInfoCol: null, trainingFeeCol: null, forceManualReview: true,
            ),
        ];
    }
}
