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
 */
final class MonthlyPaymentReportExporter
{
    public const DETAIL_HEADERS = [
        'Employee number', 'Employee name', 'Company / Guri', 'Company category', 'Monthly salary (USD)',
        'Days paid', 'Days in month', 'Gross pay (USD)', 'Deductions (USD)', 'Adjustments (USD)',
        'Payment amount (USD)', 'Payment / account number', 'Payment status', 'Data checks',
    ];

    private const MONEY_FORMAT = '#,##0.00';

    private const MISSING_FILL = 'FFF2CC';

    private const HEADER_FILL = '1F3864';

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
        $this->text($ws, 'A1', 'Monthly employee payment report');
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);

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
        $this->text($ws, 'A1', "Employees to pay - {$report['period']} (USD)".($report['is_estimate'] ? ' - ESTIMATE' : ''));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $this->text($ws, 'A2', 'Payment amount is the payroll engine\'s net payable for the month (after proration, deductions and adjustments). Highlighted cells need attention before paying.');
        $this->header($ws, 4, self::DETAIL_HEADERS);

        $rowNumber = 5;
        foreach ($report['rows'] as $r) {
            $this->text($ws, "A{$rowNumber}", (string) $r['employee_number']);
            $this->text($ws, "B{$rowNumber}", (string) $r['employee_name']);
            $this->text($ws, "C{$rowNumber}", (string) $r['company']);
            $this->text($ws, "D{$rowNumber}", (string) $r['category']);
            $r['monthly_salary_cents'] === null ? $this->missing($ws, "E{$rowNumber}") : $this->money($ws, "E{$rowNumber}", $this->usd($r['monthly_salary_cents']));
            $this->cell($ws, "F{$rowNumber}", $r['eligible_days']);
            $this->cell($ws, "G{$rowNumber}", $r['days_in_month']);
            $this->money($ws, "H{$rowNumber}", $this->usd($r['gross_cents']));
            $this->money($ws, "I{$rowNumber}", $this->usd($r['deductions_cents']));
            $this->money($ws, "J{$rowNumber}", $this->usd($r['adjustment_cents']));
            $this->money($ws, "K{$rowNumber}", $this->usd($r['net_cents']));
            $r['payment_account'] === null ? $this->missing($ws, "L{$rowNumber}") : $this->text($ws, "L{$rowNumber}", $r['payment_account']);
            $this->text($ws, "M{$rowNumber}", (string) $r['payment_status']);
            $this->text($ws, "N{$rowNumber}", $r['flags'] === [] ? 'OK' : implode('; ', $r['flags']));
            if ($r['company'] === MonthlyPaymentReportBuilder::CATEGORY_UNASSIGNED || in_array(MonthlyPaymentReportBuilder::FLAG_NO_COMPANY, $r['flags'], true)) {
                $this->missing($ws, "C{$rowNumber}", 'MISSING');
            }
            if ($r['flags'] !== []) {
                $this->fill($ws, "N{$rowNumber}", self::MISSING_FILL);
            }
            $rowNumber++;
        }

        $last = max($rowNumber - 1, 4);
        $ws->setAutoFilter("A4:N{$last}");
        $ws->freezePane('C5');
        foreach (['A' => 16, 'B' => 32, 'C' => 30, 'D' => 20, 'E' => 18, 'F' => 11, 'G' => 13, 'H' => 16, 'I' => 16, 'J' => 16, 'K' => 20, 'L' => 26, 'M' => 34, 'N' => 46] as $col => $width) {
            $ws->getColumnDimension($col)->setWidth($width);
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
