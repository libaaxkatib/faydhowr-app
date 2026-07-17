<?php

namespace App\Contracts\Dashboard;

use App\DataTransferObjects\Dashboard\DashboardKpiData;

interface DashboardWidgetInterface
{
    /**
     * The stable public API key this widget's payload is exposed under.
     */
    public function key(): string;

    /**
     * Resolve the widget's KPI payload. Widgets are fully independent and
     * must never communicate with other widgets.
     */
    public function resolve(): DashboardKpiData;
}
