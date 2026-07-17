<?php

namespace App\Contracts\Reports\Services;

use App\DataTransferObjects\Reports\BookingReportData;
use App\Enums\DashboardDateFilter;
use Carbon\CarbonImmutable;

/**
 * Read-only booking reporting contract. Implementations must reuse the
 * existing booking report repository and the shared dashboard date filters,
 * and never duplicate business logic.
 */
interface BookingReportServiceInterface
{
    /**
     * Generate the booking report for the given date filter. A null filter
     * returns all-time figures; start and end dates are only consumed by the
     * custom date range filter.
     */
    public function generate(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): BookingReportData;
}
