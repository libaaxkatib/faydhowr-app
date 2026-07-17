<?php

namespace App\Services\Reports\Services;

use App\Contracts\Reports\Services\InventoryReportServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Reports\InventoryReportData;
use App\Enums\DashboardDateFilter;
use App\Repositories\Reports\InventoryReportRepository;
use Carbon\CarbonImmutable;

/**
 * Read-only inventory report. All counts come from the existing
 * InventoryReportRepository stockLevelSummary, filtered through the shared
 * DashboardDateFilter range resolution; no query or filter logic is
 * duplicated here. The stock buckets are mutually exclusive and partition
 * every product, so the total is their sum and no separate count query is
 * needed.
 */
class InventoryReportService implements InventoryReportServiceInterface
{
    public function __construct(private InventoryReportRepository $inventoryRepository) {}

    public function generate(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): InventoryReportData {
        [$dateFrom, $dateTo] = $filter?->dateRange($startDate, $endDate) ?? [null, null];

        $filters = new NormalizedReportFilters(dateFrom: $dateFrom, dateTo: $dateTo);

        $stockLevels = $this->inventoryRepository->stockLevelSummary($filters);

        return new InventoryReportData(
            totalProducts: (int) array_sum($stockLevels),
            inStockProducts: (int) $stockLevels['in_stock'],
            lowStockProducts: (int) $stockLevels['low_stock'],
            outOfStockProducts: (int) $stockLevels['out_of_stock'],
            filter: $filter?->value ?? 'all_time',
            startDate: $dateFrom?->toIso8601String(),
            endDate: $dateTo?->toIso8601String(),
            generatedAt: CarbonImmutable::now()->toIso8601String(),
        );
    }
}
