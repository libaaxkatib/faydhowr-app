<?php

namespace App\Support\Hr\Payroll;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Writes the MonthlyPaymentReportBuilder result as an Excel file: a Summary sheet, an Employees sheet
 * (filters on, header frozen) and a "Not in payroll" sheet. Text is always written as text (names or
 * payment numbers starting with "=", "+" or "0" are never treated as formulas or numbers); money is
 * written as real numbers so Excel can total and filter it. Missing data is spelled out, never blank.
 *
 * Temporary workers (docs/HR_PAYROLL_RULES.md) are rows of the Employees sheet directly beneath the Permanent employee they covered, in the same
 * columns (A-P unchanged); the columns added at the right (Q-U) say what kind of row it is, who it is linked to, the cover period and, for a
 * Temporary, what has been paid and what is still owed. The totals of the existing columns stay the Permanent employees' own: the Temporary
 * amounts are totalled on a separate line under them, so no amount is counted twice.
 */
final class MonthlyPaymentReportExporter
{
    /** The employee's name is followed at once by the number that person is paid on and which of the two numbers it is. */
    public const DETAIL_HEADERS = [
        'Employee number', 'Employee name', 'Payment / account number', 'Payment number source',
        'Company / Guri', 'Company category', 'Monthly salary (USD)',
        'Days paid', 'Days in month', 'Gross pay (USD)', 'Deductions (USD)', 'Adjustments (USD)',
        'Payment amount (USD)', 'Payment status', 'Data checks', 'Internal employee ID',
        'Row type', 'Linked employee', 'Cover period', 'Paid to date (USD)', 'Balance (USD)',
    ];

    private const MONEY_FORMAT = '#,##0.00';

    private const MISSING_FILL = 'FFF2CC';

    private const HEADER_FILL = '1F3864';

    private const TEMPORARY_FILL = 'EAF1DD';

    /**
     * @param  array<string, mixed>  $report  from MonthlyPaymentReportBuilder::build
     */
    public function write(array $report, string $path): void
    {
        $spreadsheet = new Spreadsheet;

        $this->summarySheet($spreadsheet->getActiveSheet(), $report);
        $this->detailSheet($spreadsheet->createSheet(), $report);
        $this->notInPayrollSheet($spreadsheet->createSheet(), $report);
        $spreadsheet->setActiveSheetIndex(0);

        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
    }

    /** @param  array<string, mixed>  $report */
    private function summarySheet(Worksheet $ws, array $report): void
    {
        $s = $report['summary'];
        $ws->setTitle('Summary');
        $this->text($ws, 'A1', 'Monthly employee payment report'.($s['company_filter'] ? ' - '.($report['company_name'] ?? 'one company') : ''));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        if ($s['company_filter']) {
            $this->text($ws, 'A2', "Company list: only this company's employees and its own lines are included; the total below is this company's total.");
        }

        $facts = [
            ['Reporting month', $report['period']],
            ['Amounts based on', $s['source_label']],
            ['Generated', $report['generated_at']],
            ['Employees paid (each counted once)', $s['employee_count']],
            ['Total payment amount (USD)', $this->usd($s['total_net_cents'])],
            ['Gross pay (USD)', $this->usd($s['total_gross_cents'])],
            ['Deductions (USD)', $this->usd($s['total_deductions_cents'])],
            ['Adjustments (USD)', $this->usd($s['total_adjustment_cents'])],
            ['Employees missing a payment number', $s['missing_payment_number']],
            ['Employees missing a salary', $s['missing_salary']],
            ['Employees missing a company assignment', $s['missing_company']],
            ['Active employees not in this payroll (see sheet "Not in payroll")', $s['not_in_payroll']],
            ['Employees paid on their Phone Number (no Employee Number / Account Number recorded)', $s['phone_number_used']],
            ['Imported payment numbers that differ from the registration phone (review)', $s['register_mismatch']],
        ];
        $row = 3;
        foreach ($facts as [$label, $value]) {
            $this->text($ws, "A{$row}", (string) $label);
            $ws->getStyle("A{$row}")->getFont()->setBold(true);
            is_float($value) ? $this->money($ws, "B{$row}", $value) : $this->cell($ws, "B{$row}", $value);
            $ws->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $row++;
        }
        foreach ([11, 12, 13] as $r) {
            if ((int) $ws->getCell("B{$r}")->getValue() > 0) {
                $this->fill($ws, "A{$r}:B{$r}", self::MISSING_FILL);
            }
        }
        if ($report['is_estimate']) {
            $this->fill($ws, 'A4:B4', self::MISSING_FILL);
        }

        $row++;
        $this->text($ws, "A{$row}", 'Totals by category');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;
        $this->header($ws, $row, ['Category', 'Companies', 'Employees', 'Payment amount (USD)']);
        $first = ++$row;
        foreach ($s['by_category'] as $category) {
            $this->text($ws, "A{$row}", $category['category']);
            $this->cell($ws, "B{$row}", $category['company_count']);
            $this->cell($ws, "C{$row}", $category['employee_count']);
            $this->money($ws, "D{$row}", $this->usd($category['net_cents']));
            $row++;
        }
        $this->text($ws, "A{$row}", 'Total');
        $this->cell($ws, "C{$row}", $s['employee_count']);
        $this->money($ws, "D{$row}", $this->usd($s['total_net_cents']));
        $ws->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);

        $row += 2;
        $this->text($ws, "A{$row}", 'Totals by company / guri');
        $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;
        $this->header($ws, $row, ['Company / Guri', 'Category', 'Employees', 'Payment amount (USD)']);
        $row++;
        foreach ($s['by_company'] as $company) {
            $this->text($ws, "A{$row}", $company['company']);
            $this->text($ws, "B{$row}", $company['category']);
            $this->cell($ws, "C{$row}", $company['employee_count']);
            $this->money($ws, "D{$row}", $this->usd($company['net_cents']));
            $row++;
        }
        $this->text($ws, "A{$row}", 'Total');
        $this->money($ws, "D{$row}", $this->usd($s['total_net_cents']));
        $ws->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        if (($s['temporary_cover_count'] ?? 0) > 0) {
            $row += 2;
            $this->text($ws, "A{$row}", 'Temporary workers (paid through their cover, not through a payroll line)');
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
            foreach ([
                ['Covers with days in this month', $s['temporary_cover_count']],
                ['Temporary workers', $s['temporary_employee_count']],
                ['Temporary amount earned this month (USD)', $this->usd($s['temporary_total_cents'])],
                ['Paid to them up to the end of the month, all months of their covers (USD)', $this->usd($s['temporary_paid_to_date_cents'])],
                ['Still owed to them at the end of the month (USD)', $this->usd($s['temporary_balance_cents'])],
                ['Deducted from the Permanent employees for these covers (USD)', $this->usd($s['temporary_cover_deductions_cents'])],
                ['Earned but not deducted (cover not approved, or days already unpaid leave) (USD)', $this->usd($s['temporary_not_deducted_cents'])],
                ['Replacement deductions on the Permanent lines above (USD)', $this->usd($s['permanent_replacement_deductions_cents'])],
                ['The two deduction figures agree', $s['temporary_deductions_reconcile'] ? 'Yes' : 'NO - review: a cover or leave changed after payroll was stored'],
                ['Total payment amount including Temporary workers (USD)', $this->usd($s['total_with_temporary_cents'])],
            ] as [$label, $value]) {
                $row++;
                $this->text($ws, "A{$row}", (string) $label);
                $ws->getStyle("A{$row}")->getFont()->setBold(true);
                is_float($value) ? $this->money($ws, "B{$row}", $value) : $this->cell($ws, "B{$row}", $value);
                $ws->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }
            if (! $s['temporary_deductions_reconcile']) {
                $this->fill($ws, 'A'.($row - 1).':B'.($row - 1), self::MISSING_FILL);
            }
        }

        $this->text($ws, 'F'.($first - 1), 'An employee with two assignments is counted once in the employee total; their amount is split by company in the company table, so the company rows still add up to the total payment amount.');

        if ($report['warnings'] !== []) {
            $row += 2;
            $this->text($ws, "A{$row}", 'Payroll engine warnings for this month');
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
            foreach ($report['warnings'] as $warning) {
                $row++;
                $this->text($ws, "A{$row}", $warning);
            }
        }

        $ws->getColumnDimension('A')->setWidth(58);
        $ws->getColumnDimension('B')->setWidth(34);
        $ws->getColumnDimension('C')->setWidth(14);
        $ws->getColumnDimension('D')->setWidth(22);
        $ws->getColumnDimension('F')->setWidth(60);
        $ws->getStyle('F'.($first - 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    /** @param  array<string, mixed>  $report */
    private function detailSheet(Worksheet $ws, array $report): void
    {
        $ws->setTitle('Employees');
        $this->text($ws, 'A1', "Employees to pay - {$report['period']} (USD)".($report['summary']['company_filter'] ? ' - '.($report['company_name'] ?? 'one company') : '').($report['is_estimate'] ? ' - ESTIMATE' : ''));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->text($ws, 'A2', 'Payment amount is the payroll engine\'s net payable for the month (after proration, deductions and adjustments). Highlighted cells need attention before paying.');
        $this->header($ws, 4, self::DETAIL_HEADERS);

        $coveredBy = [];
        foreach ($report['rows'] as $r) {
            if (($r['row_type'] ?? 'permanent') === 'temporary') {
                $coveredBy[$r['linked_employee_id']][] = $r['employee_number'].' '.$r['employee_name'];
            }
        }

        $rowNumber = 5;
        foreach ($report['rows'] as $r) {
            if (($r['row_type'] ?? 'permanent') === 'temporary') {
                $this->temporaryRow($ws, $rowNumber, $r);
                $rowNumber++;

                continue;
            }

            $this->text($ws, "A{$rowNumber}", (string) $r['employee_number']);
            $this->text($ws, "B{$rowNumber}", (string) $r['employee_name']);
            $r['payment_account'] === null ? $this->missing($ws, "C{$rowNumber}") : $this->text($ws, "C{$rowNumber}", $r['payment_account']);
            $this->text($ws, "D{$rowNumber}", (string) $r['payment_account_source']);
            $this->text($ws, "E{$rowNumber}", (string) $r['company']);
            $this->text($ws, "F{$rowNumber}", (string) $r['category']);
            $r['monthly_salary_cents'] === null ? $this->missing($ws, "G{$rowNumber}") : $this->money($ws, "G{$rowNumber}", $this->usd($r['monthly_salary_cents']));
            $this->cell($ws, "H{$rowNumber}", $r['eligible_days']);
            $this->cell($ws, "I{$rowNumber}", $r['days_in_month']);
            $this->money($ws, "J{$rowNumber}", $this->usd($r['gross_cents']));
            $this->money($ws, "K{$rowNumber}", $this->usd($r['deductions_cents']));
            $this->money($ws, "L{$rowNumber}", $this->usd($r['adjustment_cents']));
            $this->money($ws, "M{$rowNumber}", $this->usd($r['net_cents']));
            $this->text($ws, "N{$rowNumber}", (string) $r['payment_status']);
            $this->text($ws, "O{$rowNumber}", $r['flags'] === [] ? 'OK' : implode('; ', $r['flags']));
            $this->text($ws, "P{$rowNumber}", (string) $r['employee_id']);
            $this->text($ws, "Q{$rowNumber}", 'Permanent');
            $this->text($ws, "R{$rowNumber}", isset($coveredBy[$r['employee_id']]) ? 'Covered by: '.implode('; ', $coveredBy[$r['employee_id']]) : '');
            if ($r['company'] === MonthlyPaymentReportBuilder::CATEGORY_UNASSIGNED || in_array(MonthlyPaymentReportBuilder::FLAG_NO_COMPANY, $r['flags'], true)) {
                $this->missing($ws, "E{$rowNumber}", 'MISSING');
            }
            if ($r['flags'] !== []) {
                $this->fill($ws, "O{$rowNumber}", self::MISSING_FILL);
            }
            $rowNumber++;
        }

        $last = max($rowNumber - 1, 4);
        $ws->setAutoFilter("A4:U{$last}");
        $ws->freezePane('D5');

        // The total of what is listed (for a company list: that company's total). Written below the filtered table, as a number.
        $totalRow = $last + 2;
        $this->text($ws, "A{$totalRow}", $report['summary']['company_filter'] ? 'Company total' : 'Total');
        $this->money($ws, "M{$totalRow}", $this->usd($report['summary']['total_net_cents']));
        $ws->getStyle("A{$totalRow}:M{$totalRow}")->getFont()->setBold(true);

        // Temporary workers are not in the total above (they have no payroll line): their own total, and the two together, are written under it.
        if (($report['summary']['temporary_cover_count'] ?? 0) > 0) {
            $this->text($ws, 'A'.($totalRow + 1), 'Temporary workers total (not included in the total above)');
            $this->money($ws, 'M'.($totalRow + 1), $this->usd($report['summary']['temporary_total_cents']));
            $this->text($ws, 'A'.($totalRow + 2), $report['summary']['company_filter'] ? 'Company total including Temporary workers' : 'Total including Temporary workers');
            $this->money($ws, 'M'.($totalRow + 2), $this->usd($report['summary']['total_with_temporary_cents']));
            $ws->getStyle('A'.($totalRow + 1).':M'.($totalRow + 2))->getFont()->setBold(true);
        }

        foreach (['A' => 16, 'B' => 32, 'C' => 26, 'D' => 34, 'E' => 30, 'F' => 20, 'G' => 18, 'H' => 11, 'I' => 13, 'J' => 16, 'K' => 16, 'L' => 16, 'M' => 20, 'N' => 34, 'O' => 46, 'P' => 14, 'Q' => 12, 'R' => 44, 'S' => 28, 'T' => 18, 'U' => 16] as $col => $width) {
            $ws->getColumnDimension($col)->setWidth($width);
        }
    }

    /**
     * A Temporary worker's row: the same columns as a Permanent's, filled from the cover. The salary cell says n/a (the cover is calculated on the
     * covered Permanent's salary, named in the cover period), the amounts are the cover's entitlement for the month, and the last columns give
     * what was paid up to the end of the month and what is still owed.
     *
     * @param  array<string, mixed>  $r
     */
    private function temporaryRow(Worksheet $ws, int $rowNumber, array $r): void
    {
        $this->text($ws, "A{$rowNumber}", (string) $r['employee_number']);
        $this->text($ws, "B{$rowNumber}", (string) $r['employee_name']);
        $r['payment_account'] === null ? $this->missing($ws, "C{$rowNumber}") : $this->text($ws, "C{$rowNumber}", $r['payment_account']);
        $this->text($ws, "D{$rowNumber}", (string) $r['payment_account_source']);
        $this->text($ws, "E{$rowNumber}", (string) $r['company']);
        $this->text($ws, "F{$rowNumber}", (string) $r['category']);
        $this->text($ws, "G{$rowNumber}", 'n/a (Temporary)');
        $this->cell($ws, "H{$rowNumber}", $r['eligible_days']);
        $this->cell($ws, "I{$rowNumber}", $r['days_in_month']);
        $this->money($ws, "J{$rowNumber}", $this->usd($r['gross_cents']));
        $this->money($ws, "K{$rowNumber}", 0.0);
        $this->money($ws, "L{$rowNumber}", 0.0);
        $this->money($ws, "M{$rowNumber}", $this->usd($r['net_cents']));
        $this->text($ws, "N{$rowNumber}", (string) $r['payment_status']);
        $this->text($ws, "O{$rowNumber}", $r['flags'] === [] ? 'OK' : implode('; ', $r['flags']));
        $this->text($ws, "P{$rowNumber}", (string) $r['employee_id']);
        $this->text($ws, "Q{$rowNumber}", 'Temporary');
        $this->text($ws, "R{$rowNumber}", 'Covers: '.$r['linked_employee_number'].' '.$r['linked_employee_name']);
        $this->text($ws, "S{$rowNumber}", $r['cover_from'].' to '.($r['cover_open_ended'] ? 'open' : (string) $r['cover_through']).($r['planned_end_date'] !== null ? ' (planned end '.$r['planned_end_date'].')' : ''));
        $this->money($ws, "T{$rowNumber}", $this->usd($r['paid_cents']));
        $r['balance_cents'] === null ? $this->missing($ws, "U{$rowNumber}") : $this->money($ws, "U{$rowNumber}", $this->usd($r['balance_cents']));

        $this->fill($ws, "A{$rowNumber}:N{$rowNumber}", self::TEMPORARY_FILL);
        $this->fill($ws, "P{$rowNumber}:U{$rowNumber}", self::TEMPORARY_FILL);
        $this->fill($ws, "O{$rowNumber}", $r['flags'] === [] ? self::TEMPORARY_FILL : self::MISSING_FILL);
        $ws->getStyle("B{$rowNumber}")->getAlignment()->setIndent(1);
        if ($r['payment_account'] === null) {
            $this->missing($ws, "C{$rowNumber}");
        }
        if ($r['balance_cents'] === null) {
            $this->missing($ws, "U{$rowNumber}");
        }
    }

    /** @param  array<string, mixed>  $report */
    private function notInPayrollSheet(Worksheet $ws, array $report): void
    {
        $ws->setTitle('Not in payroll');
        $this->text($ws, 'A1', "Active employees with no payment in {$report['period']} (not included in any total)");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->header($ws, 3, ['Employee number', 'Employee name', 'Status', 'Data check', 'Reason']);

        $rowNumber = 4;
        foreach ($report['not_in_payroll'] as $r) {
            $this->text($ws, "A{$rowNumber}", (string) $r['employee_number']);
            $this->text($ws, "B{$rowNumber}", (string) $r['employee_name']);
            $this->text($ws, "C{$rowNumber}", (string) $r['status']);
            $this->text($ws, "D{$rowNumber}", (string) $r['flag']);
            $this->text($ws, "E{$rowNumber}", (string) $r['reason']);
            $this->fill($ws, "D{$rowNumber}", self::MISSING_FILL);
            $rowNumber++;
        }

        $ws->setAutoFilter('A3:E'.max($rowNumber - 1, 3));
        $ws->freezePane('A4');
        foreach (['A' => 16, 'B' => 32, 'C' => 12, 'D' => 30, 'E' => 90] as $col => $width) {
            $ws->getColumnDimension($col)->setWidth($width);
        }
    }

    private function usd(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function text(Worksheet $ws, string $cell, string $value): void
    {
        $ws->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function cell(Worksheet $ws, string $cell, mixed $value): void
    {
        is_int($value) || is_float($value)
            ? $ws->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC)
            : $this->text($ws, $cell, (string) $value);
    }

    private function money(Worksheet $ws, string $cell, float $value): void
    {
        $ws->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
        $ws->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
    }

    private function missing(Worksheet $ws, string $cell, string $label = 'MISSING'): void
    {
        $this->text($ws, $cell, $label);
        $this->fill($ws, $cell, self::MISSING_FILL);
        $ws->getStyle($cell)->getFont()->setBold(true)->getColor()->setRGB('C00000');
    }

    private function fill(Worksheet $ws, string $range, string $rgb): void
    {
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    /** @param  list<string>  $headers */
    private function header(Worksheet $ws, int $row, array $headers): void
    {
        foreach ($headers as $index => $header) {
            $ws->setCellValueExplicit([$index + 1, $row], $header, DataType::TYPE_STRING);
        }
        $range = 'A'.$row.':'.chr(64 + count($headers)).$row;
        $ws->getStyle($range)->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::HEADER_FILL]]]);
        $ws->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    }
}
