<?php

namespace App\Services\Reports;

use App\Actions\Report\NormalizeReportFiltersAction;
use App\Contracts\Reports\Storage\ReportStorageInterface;
use App\Events\Reports\ReportExportCompleted;
use App\Events\Reports\ReportExportFailed;
use App\Models\ReportExport;
use App\Repositories\Reports\ReportExportRepository;
use Throwable;

class ReportExportManager
{
    public function __construct(
        private ReportManager $reportManager,
        private ReportExportRepository $reportExports,
        private NormalizeReportFiltersAction $normalizeReportFilters,
        private ReportStorageInterface $reportStorage,
    ) {}

    /**
     * Process a queued report export: regenerate the report data through the
     * reporting layer, reserve the export location, and update the status.
     * Actual PDF/XLSX rendering is implemented in a later phase; for now a
     * placeholder file is written to the reserved path.
     */
    public function process(int $reportExportId): ReportExport
    {
        $export = $this->reportExports->findOrFail($reportExportId);

        if ($export->status->isTerminal()) {
            return $export;
        }

        $this->reportExports->markProcessing($export);

        try {
            $filters = $this->normalizeReportFilters->handle($export->filters ?? []);

            $this->reportManager->generatorFor($export->report_type)->generate($filters);

            $filePath = $this->reportStorage->reservePath($export);
            $this->reportStorage->writePlaceholder($export, $filePath);

            $export = $this->reportExports->markCompleted($export, $filePath);

            ReportExportCompleted::dispatch($export);
        } catch (Throwable $exception) {
            report($exception);

            $export = $this->reportExports->markFailed($export, $exception->getMessage());

            ReportExportFailed::dispatch($export);
        }

        return $export;
    }
}
