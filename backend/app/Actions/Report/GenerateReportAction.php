<?php

namespace App\Actions\Report;

use App\Contracts\Reports\ReportManagerInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Report;

class GenerateReportAction
{
    public function __construct(private ReportManagerInterface $reportManager) {}

    /**
     * Generate the report payload and persist the report metadata record.
     * Filters must already be normalized by NormalizeReportFiltersAction;
     * this action never receives raw request arrays.
     * V1 always generates a JSON payload; pdf/excel formats are metadata only.
     *
     * @return array{report: Report, payload: array<string, mixed>}
     */
    public function handle(
        Admin $admin,
        ReportType $type,
        ?NormalizedReportFilters $filters = null,
        ReportFormat $format = ReportFormat::Json,
        ?ReportCursorPagination $pagination = null,
    ): array {
        $filters ??= new NormalizedReportFilters;

        $payload = $this->reportManager->generatorFor($type)->generate($filters, $pagination);

        $report = Report::query()->create([
            'report_type' => $type,
            'format' => $format,
            'filters' => $filters->toArray(),
            'generated_by' => $admin->id,
            'generated_at' => now(),
        ]);

        return [
            'report' => $report,
            'payload' => $payload,
        ];
    }
}
