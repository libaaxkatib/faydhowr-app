<?php

namespace App\Services\Dashboard\Widgets;

use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\Contracts\Dashboard\DashboardWidgetInterface;
use App\DataTransferObjects\Dashboard\DashboardKpiData;

class PaymentSummaryWidget implements DashboardWidgetInterface
{
    public function __construct(private DashboardQueryServiceInterface $dashboardQueries) {}

    public function key(): string
    {
        return 'payments';
    }

    /**
     * All data retrieval is delegated to the centralized query service.
     */
    public function resolve(): DashboardKpiData
    {
        return $this->dashboardQueries->paymentSummary();
    }
}
