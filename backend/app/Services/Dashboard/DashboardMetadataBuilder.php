<?php

namespace App\Services\Dashboard;

use App\Contracts\Dashboard\DashboardMetadataBuilderInterface;
use App\DataTransferObjects\Dashboard\DashboardCacheData;
use App\DataTransferObjects\Dashboard\DashboardFilterData;
use App\DataTransferObjects\Dashboard\DashboardMetadataData;
use App\Enums\DashboardDateFilter;
use Carbon\CarbonImmutable;

class DashboardMetadataBuilder implements DashboardMetadataBuilderInterface
{
    public const VERSION = 1;

    /**
     * Start and end dates are only present for the custom date range filter,
     * mirroring the request inputs; predefined periods resolve their own
     * ranges inside the query service.
     */
    public function build(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): DashboardMetadataData {
        return new DashboardMetadataData(
            generatedAt: CarbonImmutable::now()->toIso8601String(),
            cache: new DashboardCacheData(
                enabled: true,
                ttlSeconds: DashboardQueryService::CACHE_TTL_SECONDS,
            ),
            filter: new DashboardFilterData(
                type: $filter?->value ?? 'all_time',
                startDate: $startDate?->toIso8601String(),
                endDate: $endDate?->toIso8601String(),
            ),
            version: self::VERSION,
        );
    }
}
