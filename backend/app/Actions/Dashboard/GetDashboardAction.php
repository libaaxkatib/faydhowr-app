<?php

namespace App\Actions\Dashboard;

use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\DataTransferObjects\Dashboard\DashboardKpiData;
use App\Enums\DashboardDateFilter;
use App\Services\Dashboard\DashboardManager;
use Carbon\CarbonImmutable;

class GetDashboardAction
{
    public function __construct(
        private DashboardManager $dashboardManager,
        private DashboardQueryServiceInterface $dashboardQueries,
    ) {}

    /**
     * Aggregated widget dashboard payload; the optional date filter is handed
     * to the query service (which owns all filter creation) and all widget
     * resolution is delegated to the DashboardManager. This action runs no
     * queries.
     *
     * @return array<string, DashboardKpiData>
     */
    public function handle(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): array {
        $this->dashboardQueries->applyDateFilter($filter, $startDate, $endDate);

        return $this->dashboardManager->aggregate();
    }
}
