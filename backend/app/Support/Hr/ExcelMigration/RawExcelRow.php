<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * One raw, trimmed (but not yet normalized/validated) row read from a sheet
 * in the HR Excel workbook, per the column mapping in SheetConfig.
 */
final readonly class RawExcelRow
{
    public function __construct(
        public string $sheetName,
        public int $rowNumber,
        public ?string $dateRaw,
        public ?string $name,
        public ?string $phoneRaw,
        public ?string $jobRaw,
        public ?string $location,
        public ?string $age,
        public ?string $maritalStatus,
        public ?string $livesWith,
        public ?string $reference,
        public ?string $stageRaw,
        public ?string $experience,
        public ?string $otherContactPhone,
        public ?string $otherContactName,
        public ?string $otherInfo,
        public ?string $trainingFeeRaw,
        public bool $isSupervisorSheet,
    ) {}
}
