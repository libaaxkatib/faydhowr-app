<?php

namespace App\Contracts\Reports\Services;

use App\DataTransferObjects\Reports\RevenueReportData;
use App\Enums\DashboardDateFilter;
use Carbon\CarbonImmutable;

/**
 * Read-only revenue reporting contract. Implementations must reuse the
 * existing payment report repository and the shared dashboard date filters,
 * and never duplicate business logic.
 */
interface RevenueReportServiceInterface
{
    /**
     * Generate the revenue report for the given date filter. A null filter
     * returns all-time figures; start and end dates are only consumed by the
     * custom date range filter.
     */
    public function generate(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): RevenueReportData;
}
