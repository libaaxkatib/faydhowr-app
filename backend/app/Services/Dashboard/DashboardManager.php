<?php

namespace App\Services\Dashboard;

use App\Contracts\Dashboard\DashboardWidgetInterface;
use App\Contracts\Dashboard\DashboardWidgetRegistryInterface;
use App\DataTransferObjects\Dashboard\DashboardKpiData;

class DashboardManager
{
    public function __construct(private DashboardWidgetRegistryInterface $registry) {}

    /**
     * @return list<DashboardWidgetInterface>
     */
    public function widgets(): array
    {
        return $this->registry->enabled();
    }

    /**
     * Execute every enabled widget and aggregate the KPI payloads keyed by
     * widget key, preserving the registry's execution order.
     *
     * @return array<string, DashboardKpiData>
     */
    public function aggregate(): array
    {
        $dashboard = [];

        foreach ($this->registry->enabled() as $widget) {
            $dashboard[$widget->key()] = $widget->resolve();
        }

        return $dashboard;
    }
}
