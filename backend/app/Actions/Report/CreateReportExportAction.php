<?php

namespace App\Actions\Report;

use App\Data\Reports\NormalizedReportFilters;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Events\Reports\ReportExportRequested;
use App\Models\Admin;
use App\Models\Report;
use App\Models\ReportExport;
use App\Repositories\Reports\ReportExportRepository;

class CreateReportExportAction
{
    public function __construct(private ReportExportRepository $reportExports) {}

    /**
     * Create a pending report export record and dispatch the requested event.
     * The queued job picks it up from there; this action never generates files.
     * Filters must already be normalized by NormalizeReportFiltersAction.
     */
    public function handle(
        Admin $admin,
        Report $report,
        ReportExportFormat $format,
        ?NormalizedReportFilters $filters = null,
    ): ReportExport {
        $filters ??= new NormalizedReportFilters;

        $export = $this->reportExports->create([
            'report_id' => $report->id,
            'report_type' => $report->report_type,
            'requested_by' => $admin->id,
            'format' => $format,
            'filters' => $filters->toArray(),
            'status' => ReportExportStatus::Pending,
        ]);

        ReportExportRequested::dispatch($export);

        return $export;
    }
}
