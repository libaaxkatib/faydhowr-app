<?php

namespace App\Actions\Report;

use App\Data\Reports\ReportCursorPagination;
use App\Repositories\Reports\ReportExportRepository;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ListReportExportsAction
{
    public function __construct(private ReportExportRepository $reportExports) {}

    /**
     * List export history; all filtering, sorting, and pagination is
     * delegated to the repository. Filters must be validated input only.
     *
     * @param  array<string, mixed>  $filters
     */
    public function handle(
        array $filters,
        ReportCursorPagination $pagination,
        string $sortDirection = 'desc',
    ): CursorPaginator {
        return $this->reportExports->paginateHistory($filters, $pagination, $sortDirection);
    }
}
