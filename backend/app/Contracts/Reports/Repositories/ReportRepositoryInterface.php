<?php

namespace App\Contracts\Reports\Repositories;

use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use Illuminate\Contracts\Pagination\CursorPaginator;

interface ReportRepositoryInterface
{
    public function supports(ReportType $type): bool;

    /**
     * Compute the report summary for the given normalized filters.
     *
     * @return array<string, mixed>
     */
    public function summary(NormalizedReportFilters $filters): array;

    /**
     * Return the cursor-paginated report dataset for the given normalized filters.
     * Items are plain row arrays; ordering must be deterministic.
     */
    public function rows(NormalizedReportFilters $filters, ReportCursorPagination $pagination): CursorPaginator;
}
