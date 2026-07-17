<?php

namespace App\Actions\Dashboard;

use App\Services\Dashboard\DashboardManager;

class GetDashboardAction
{
    public function __construct(private DashboardManager $dashboardManager) {}

    /**
     * Aggregated widget dashboard payload; all widget resolution is
     * delegated to the DashboardManager. This action runs no queries.
     *
     * @return array<string, array<string, mixed>>
     */
    public function handle(): array
    {
        return $this->dashboardManager->aggregate();
    }
}
