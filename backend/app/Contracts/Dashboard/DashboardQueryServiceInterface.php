<?php

namespace App\Contracts\Dashboard;

use App\DataTransferObjects\Dashboard\DashboardKpiData;
use App\Enums\DashboardDateFilter;
use Carbon\CarbonImmutable;

/**
 * Centralized dashboard data retrieval contract. Widgets may only depend on
 * this interface; repository access and filter creation are owned exclusively
 * by the implementation.
 */
interface DashboardQueryServiceInterface
{
    /**
     * Apply a dashboard-wide date filter to every subsequent summary. Passing
     * null restores all-time totals. Start and end dates are only consumed for
     * the custom date range filter.
     */
    public function applyDateFilter(
        ?DashboardDateFilter $filter,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): void;

    public function bookingSummary(): DashboardKpiData;

    public function quotationSummary(): DashboardKpiData;

    public function orderSummary(): DashboardKpiData;

    public function paymentSummary(): DashboardKpiData;

    public function revenueSummary(): DashboardKpiData;

    public function inventorySummary(): DashboardKpiData;

    public function customerSummary(): DashboardKpiData;

    public function adminSummary(): DashboardKpiData;

    public function storeOrderSummary(): DashboardKpiData;

    public function supplierSummary(): DashboardKpiData;

    public function purchaseOrderSummary(): DashboardKpiData;

    public function goodsReceiptSummary(): DashboardKpiData;
}
