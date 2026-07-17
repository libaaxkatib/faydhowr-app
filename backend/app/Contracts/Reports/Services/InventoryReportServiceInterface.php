<?php

namespace App\Contracts\Reports\Services;

use App\DataTransferObjects\Reports\InventoryReportData;
use App\Enums\DashboardDateFilter;
use Carbon\CarbonImmutable;

/**
 * Read-only inventory reporting contract. Implementations must reuse the
 * existing inventory report repository and the shared dashboard date
 * filters, and never duplicate business logic.
 */
interface InventoryReportServiceInterface
{
    /**
     * Generate the inventory report for the given date filter. A null filter
     * returns all-time figures; start and end dates are only consumed by the
     * custom date range filter.
     */
    public function generate(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): InventoryReportData;
}
