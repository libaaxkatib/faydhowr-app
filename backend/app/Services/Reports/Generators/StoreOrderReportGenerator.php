<?php

namespace App\Services\Reports\Generators;

use App\Contracts\Reports\Generators\ReportGeneratorInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Repositories\Reports\StoreOrderReportRepository;

class StoreOrderReportGenerator implements ReportGeneratorInterface
{
    public function __construct(private StoreOrderReportRepository $repository) {}

    public function supports(ReportType $type): bool
    {
        return $type === ReportType::StoreOrders;
    }

    /**
     * @return array{report_type: string, generated_at: string, applied_filters: array<string, mixed>, summary: array<string, mixed>, rows: list<array<string, mixed>>, pagination: array{has_more: bool, next_cursor: ?string, previous_cursor: ?string, per_page: int, count: int}}
     */
    public function generate(NormalizedReportFilters $filters, ?ReportCursorPagination $pagination = null): array
    {
        $rows = $this->repository->rows($filters, $pagination ?? new ReportCursorPagination);

        return [
            'report_type' => ReportType::StoreOrders->value,
            'generated_at' => now()->toISOString(),
            'applied_filters' => $filters->toArray(),
            'summary' => $this->repository->summary($filters),
            'rows' => $rows->items(),
            'pagination' => ReportCursorPagination::metadata($rows),
        ];
    }
}
