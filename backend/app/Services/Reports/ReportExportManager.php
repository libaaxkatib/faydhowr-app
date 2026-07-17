<?php

namespace App\Services\Reports;

use App\Actions\Report\NormalizeReportFiltersAction;
use App\Contracts\Reports\Excel\ExcelReportGeneratorInterface;
use App\Contracts\Reports\Pdf\PdfReportGeneratorInterface;
use App\Contracts\Reports\ReportManagerInterface;
use App\Contracts\Reports\Storage\ReportStorageInterface;
use App\Enums\DashboardDateFilter;
use App\Enums\ReportExportFormat;
use App\Events\Reports\ReportExportCompleted;
use App\Events\Reports\ReportExportFailed;
use App\Models\ReportExport;
use App\Repositories\Reports\ReportExportRepository;
use Carbon\CarbonImmutable;
use Throwable;

class ReportExportManager
{
    public function __construct(
        private ReportManagerInterface $reportManager,
        private ReportExportRepository $reportExports,
        private NormalizeReportFiltersAction $normalizeReportFilters,
        private ReportStorageInterface $reportStorage,
        private PdfReportGeneratorInterface $pdfGenerator,
        private ExcelReportGeneratorInterface $excelGenerator,
    ) {}

    /**
     * Process a queued report export. Report types backed by a summary
     * report service render a real PDF/XLSX document through the dedicated
     * generators; the remaining legacy types keep the placeholder behavior
     * until their services exist.
     */
    public function process(int $reportExportId): ReportExport
    {
        $export = $this->reportExports->findOrFail($reportExportId);

        if ($export->status->isTerminal()) {
            return $export;
        }

        $this->reportExports->markProcessing($export);

        try {
            $filePath = $this->reportStorage->reservePath($export);

            if ($this->reportManager->supportsSummary($export->report_type)) {
                $this->reportStorage->write($export, $filePath, $this->renderSummaryDocument($export));
            } else {
                $filters = $this->normalizeReportFilters->handle($export->filters ?? []);

                $this->reportManager->generatorFor($export->report_type)->generate($filters);

                $this->reportStorage->writePlaceholder($export, $filePath);
            }

            $export = $this->reportExports->markCompleted($export, $filePath);

            ReportExportCompleted::dispatch($export);
        } catch (Throwable $exception) {
            report($exception);

            $export = $this->reportExports->markFailed($export, $exception->getMessage());

            ReportExportFailed::dispatch($export);
        }

        return $export;
    }

    /**
     * Generate the summary DTO through the report manager and render it in
     * the requested format.
     */
    private function renderSummaryDocument(ReportExport $export): string
    {
        [$filter, $startDate, $endDate] = $this->summaryDateContext($export);

        $summary = $this->reportManager->summaryFor($export->report_type, $filter, $startDate, $endDate);

        return match ($export->format) {
            ReportExportFormat::Pdf => $this->pdfGenerator->generate($summary),
            ReportExportFormat::Xlsx => $this->excelGenerator->generate($summary),
        };
    }

    /**
     * Map the export's stored date filters onto the shared dashboard date
     * filter contract: a stored date range becomes a custom date range and
     * no dates mean all-time. Other stored filters are not part of the
     * summary report contract.
     *
     * @return array{0: ?DashboardDateFilter, 1: ?CarbonImmutable, 2: ?CarbonImmutable}
     */
    private function summaryDateContext(ReportExport $export): array
    {
        $filters = $export->filters ?? [];

        $startDate = isset($filters['date_from']) ? CarbonImmutable::parse((string) $filters['date_from']) : null;
        $endDate = isset($filters['date_to']) ? CarbonImmutable::parse((string) $filters['date_to']) : null;

        if ($startDate === null && $endDate === null) {
            return [null, null, null];
        }

        return [DashboardDateFilter::CustomDateRange, $startDate, $endDate];
    }
}
