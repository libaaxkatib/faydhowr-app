<?php

namespace App\Support\Hr\ExcelMigration;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads one worksheet into a list of RawExcelRow per its SheetConfig.
 * Purely mechanical (trim + read-by-position) — no normalization or
 * business interpretation happens here, that's PhoneNormalizer/StageDictionary.
 */
final class SheetRowParser
{
    /**
     * @return list<RawExcelRow>
     */
    public function parse(Worksheet $sheet, SheetConfig $config): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $lastRow = $config->endRow !== null ? min($config->endRow, $highestRow) : $highestRow;
        $rows = [];

        for ($row = $config->startRow; $row <= $lastRow; $row++) {
            $name = $this->cell($sheet, $config->nameCol, $row);
            $phone = $this->cell($sheet, $config->phoneCol, $row);

            if ($name === null && $phone === null) {
                continue; // fully empty row
            }

            $rows[] = new RawExcelRow(
                sheetName: $config->sheetName,
                rowNumber: $row,
                dateRaw: $this->cell($sheet, $config->dateCol, $row),
                name: $name,
                phoneRaw: $phone,
                jobRaw: $this->cell($sheet, $config->jobCol, $row),
                location: $this->cell($sheet, $config->locationCol, $row),
                age: $this->cell($sheet, $config->ageCol, $row),
                maritalStatus: $this->cell($sheet, $config->maritalStatusCol, $row),
                livesWith: $this->cell($sheet, $config->livesWithCol, $row),
                reference: $this->cell($sheet, $config->referenceCol, $row),
                stageRaw: $this->cell($sheet, $config->stageCol, $row),
                experience: $this->cell($sheet, $config->experienceCol, $row),
                otherContactPhone: $this->cell($sheet, $config->otherContactPhoneCol, $row),
                otherContactName: $this->cell($sheet, $config->otherContactNameCol, $row),
                otherInfo: $this->cell($sheet, $config->otherInfoCol, $row),
                trainingFeeRaw: $this->cell($sheet, $config->trainingFeeCol, $row),
                isSupervisorSheet: $config->isSupervisorSheet,
            );
        }

        return $rows;
    }

    private function cell(Worksheet $sheet, ?int $col, int $row): ?string
    {
        if ($col === null) {
            return null;
        }

        $coord = Coordinate::stringFromColumnIndex($col).$row;
        $value = $sheet->getCell($coord)->getValue();

        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            return null; // formula objects etc. — not expected in these sheets, skip rather than misread
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
