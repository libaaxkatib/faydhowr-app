<?php

namespace App\Contracts\Reports\Generators;

use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;

interface ReportGeneratorInterface
{
    public function supports(ReportType $type): bool;

    /**
     * Generate the V1 JSON report payload for the given normalized filters.
     * Generators must not re-validate filters; normalization happens exactly once upstream.
     * Rows are cursor-paginated; pagination defaults to the first page with the default limit.
     *
     * @return array{report_type: string, generated_at: string, applied_filters: array<string, mixed>, summary: array<string, mixed>, rows: list<array<string, mixed>>, pagination: array{has_more: bool, next_cursor: ?string, previous_cursor: ?string, per_page: int, count: int}}
     */
    public function generate(NormalizedReportFilters $filters, ?ReportCursorPagination $pagination = null): array;
}
