<?php

namespace App\Contracts\Dashboard;

use App\DataTransferObjects\Dashboard\DashboardMetadataData;
use App\Enums\DashboardDateFilter;
use Carbon\CarbonImmutable;

/**
 * Builds the top-level dashboard response metadata. Metadata construction is
 * owned exclusively by the implementation; controllers, widgets, the manager,
 * and the query service must never build metadata themselves.
 */
interface DashboardMetadataBuilderInterface
{
    public function build(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): DashboardMetadataData;
}
