<?php

namespace App\Support\Hr\Payroll;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Writes the rows from PayrollSheetBuilder to an Excel file. Temporary
 * employees are written as indented child rows directly under the Permanent
 * employee they covered, in the order supplied by the builder. Missing
 * values are labelled, never left to look like zero.
 */
final class PayrollWorkbookExporter
{
    public const HEADERS = [
        'Employee ID', 'Employee name', 'Row type', 'Company', 'Workplace', 'Status', 'Employment type', 'Payroll month',
        'Eligible days', 'Days in month', 'Gross / prorated salary (USD)', 'Replacement start', 'Replacement end',
        'Verified workdays', 'Daily rate (USD)', 'Replacement amount (USD)', 'Replacement deduction (USD)',
        'Other deductions (itemized)', 'Total deductions (USD)', 'Net payable (USD)', 'Payroll status', 'Notes',
    ];

    /**
     * @param  array{period: string, rows: list<array<string, mixed>>, totals: array<string, int>, reconciliation: array{children_approved_cents: int, parent_deductions_cents: int, ok: bool}}  $sheet
     */
    public function write(array $sheet, string $path, string $title = 'Month-end payroll'): void
    {
        $spreadsheet = new Spreadsheet;
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('Payroll '.$sheet['period']);
        $ws->setCellValue('A1', "{$title} - {$sheet['period']} (USD)");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        foreach (self::HEADERS as $index => $header) {
            $ws->setCellValue([$index + 1, 3], $header);
            $ws->getColumnDimensionByColumn($index + 1)->setWidth(match (true) {
                $index === 1 => 34,
                $index === 17, $index === 21 => 38,
                default => 16,
            });
        }
        $ws->getStyle('A3:V3')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3864']]]);

        $rowNumber = 4;
        foreach ($sheet['rows'] as $row) {
            $isChild = $row['type'] === 'temporary';
            $values = $isChild
                ? [$row['employee_id'], '    '.$row['name'], 'Temporary (covering '.$row['parent_employee_id'].')', $row['company'], $row['workplace'] ?? 'Missing', 'Active', 'Temporary', $row['period'], 'n/a', 'n/a', 'Not applicable', $row['start_date'], $row['end_date'] ?? 'Open', $row['verified_days'], $row['daily_rate'], PayrollMoney::format($row['amount_cents']), $row['deducted_from_parent'] ? 'counted in parent' : 'not deducted', '', 'Not applicable', 'Not applicable', 'Child of '.$row['parent_employee_id'], $row['note']]
                : [$row['employee_id'], $row['name'], 'Permanent', $row['company'], $row['workplace'] ?? 'Missing', $row['status'], 'Permanent', $row['period'], $row['eligible_days'], $row['days_in_month'], PayrollMoney::format($row['gross_cents']), '', '', '', '', '', PayrollMoney::format($row['replacement_deduction_cents']), $this->itemize($row['other_deductions']), PayrollMoney::format($row['total_deductions_cents']), PayrollMoney::format($row['net_cents']), $row['payroll_status'], $row['note']];

            foreach ($values as $index => $value) {
                $ws->setCellValueExplicit([$index + 1, $rowNumber], (string) $value, DataType::TYPE_STRING);
            }

            if ($isChild) {
                $ws->getStyle("A{$rowNumber}:V{$rowNumber}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
                $ws->getStyle("A{$rowNumber}:V{$rowNumber}")->getFont()->setItalic(true);
            } else {
                $ws->getStyle("A{$rowNumber}:V{$rowNumber}")->getFont()->setBold(true);
            }

            $rowNumber++;
        }

        $totals = $sheet['totals'];
        $rowNumber++;
        $ws->setCellValue("B{$rowNumber}", 'TOTALS (Permanent rows only - Temporary child rows are not added again)');
        $ws->setCellValueExplicit("K{$rowNumber}", PayrollMoney::format($totals['gross_cents']), DataType::TYPE_STRING);
        $ws->setCellValueExplicit("Q{$rowNumber}", PayrollMoney::format($totals['replacement_deduction_cents']), DataType::TYPE_STRING);
        $ws->setCellValueExplicit("S{$rowNumber}", PayrollMoney::format($totals['replacement_deduction_cents'] + $totals['other_deduction_cents']), DataType::TYPE_STRING);
        $ws->setCellValueExplicit("T{$rowNumber}", PayrollMoney::format($totals['net_cents']), DataType::TYPE_STRING);
        $ws->getStyle("A{$rowNumber}:V{$rowNumber}")->getFont()->setBold(true);
        $rowNumber++;
        $ws->setCellValue("B{$rowNumber}", 'Reconciliation: approved Temporary amounts = Permanent replacement deductions');
        $ws->setCellValue("Q{$rowNumber}", PayrollMoney::format($sheet['reconciliation']['children_approved_cents']).' vs '.PayrollMoney::format($sheet['reconciliation']['parent_deductions_cents']));
        $ws->setCellValue("T{$rowNumber}", $sheet['reconciliation']['ok'] ? 'OK' : 'MISMATCH');
        $ws->freezePane('C4');

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
    }

    /**
     * @param  list<array{type: string, cents: int}>  $deductions
     */
    private function itemize(array $deductions): string
    {
        return $deductions === [] ? 'None' : implode('; ', array_map(fn (array $d): string => $d['type'].' '.PayrollMoney::format($d['cents']), $deductions));
    }
}
