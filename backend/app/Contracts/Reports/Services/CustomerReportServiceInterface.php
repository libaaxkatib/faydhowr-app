<?php

namespace App\Contracts\Reports\Services;

use App\DataTransferObjects\Reports\CustomerReportData;
use App\Enums\DashboardDateFilter;
use Carbon\CarbonImmutable;

/**
 * Read-only customer reporting contract. Implementations must reuse the
 * existing customer report repository and the shared dashboard date filters,
 * and never duplicate business logic.
 */
interface CustomerReportServiceInterface
{
    /**
     * Generate the customer report for the given date filter. A null filter
     * returns all-time figures; start and end dates are only consumed by the
     * custom date range filter.
     */
    public function generate(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): CustomerReportData;
}
