<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum DashboardDateFilter: string
{
    case Today = 'today';

    case Yesterday = 'yesterday';

    case Last7Days = 'last_7_days';

    case Last30Days = 'last_30_days';

    case ThisMonth = 'this_month';

    case LastMonth = 'last_month';

    case CustomDateRange = 'custom_date_range';

    /**
     * Resolve the concrete date range for this filter. This is the single
     * source of date-range truth shared by the dashboard and the reports
     * module, so both always produce identical figures for the same filter.
     * Start and end dates are only consumed by the custom date range filter.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public function dateRange(
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): array {
        $now = CarbonImmutable::now();

        return match ($this) {
            self::Today => [$now->startOfDay(), $now->endOfDay()],
            self::Yesterday => [
                $now->subDay()->startOfDay(),
                $now->subDay()->endOfDay(),
            ],
            self::Last7Days => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            self::Last30Days => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            self::ThisMonth => [$now->startOfMonth(), $now->endOfMonth()],
            self::LastMonth => [
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow()->endOfMonth(),
            ],
            self::CustomDateRange => [
                $startDate?->startOfDay(),
                $endDate?->endOfDay(),
            ],
        };
    }
}
