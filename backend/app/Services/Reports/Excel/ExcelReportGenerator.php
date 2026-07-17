<?php

namespace App\Services\Reports\Excel;

use App\Contracts\Reports\Excel\ExcelReportGeneratorInterface;
use App\Contracts\Reports\ReportDataInterface;
use App\Support\Reports\ReportDataPresenter;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Presentation-only Excel renderer for report DTOs. The workbook content is
 * derived entirely from the DTO's serialized payload, so an identical DTO
 * always produces identical spreadsheet content. Workbook creation and
 * modification metadata are pinned to the DTO's generated-at timestamp to
 * keep the document deterministic. No repositories, business logic, or
 * calculations are involved.
 */
class ExcelReportGenerator implements ExcelReportGeneratorInterface
{
    public function generate(ReportDataInterface $report): string
    {
        $payload = $report->toArray();
        $reportName = ReportDataPresenter::reportName($report);
        $generatedAt = (string) ($payload['generated_at'] ?? '');

        $spreadsheet = new Spreadsheet;

        $timestamp = CarbonImmutable::parse($generatedAt)->getTimestamp();
        $spreadsheet->getProperties()
            ->setCreator('Fayadhowr ERP')
            ->setTitle($reportName)
            ->setCreated($timestamp)
            ->setModified($timestamp);

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $rows = [
            [(string) config('app.name')],
            [$reportName],
            ['Generated At', $generatedAt],
            [null],
            ['Filter Information'],
            ['Filter', $payload['filter'] ?? null],
            ['Start Date', $payload['start_date'] ?? 'N/A'],
            ['End Date', $payload['end_date'] ?? 'N/A'],
            [null],
            ['Report Metrics'],
            ['Metric', 'Value'],
        ];

        foreach (ReportDataPresenter::metrics($payload) as $label => $value) {
            $rows[] = [$label, $value];
        }

        $sheet->fromArray($rows, null, 'A1', true);

        $sheet->getStyle('A1:A2')->getFont()->setBold(true);
        $sheet->getStyle('A5')->getFont()->setBold(true);
        $sheet->getStyle('A10')->getFont()->setBold(true);
        $sheet->getStyle('A11:B11')->getFont()->setBold(true);

        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }
}
