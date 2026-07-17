<?php

namespace App\Actions\Admin;

use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\DataTransferObjects\Dashboard\DashboardKpiData;

class GetDashboardStatisticsAction
{
    public function __construct(private DashboardQueryServiceInterface $dashboardQueries) {}

    /**
     * Legacy statistics block, unified onto the centralized dashboard query
     * service so statistics and widgets always share the same filter context,
     * repositories, and cache entries. Only module-visibility filtering
     * remains here; no counting or caching happens in this action.
     *
     * @param  list<string>  $visibleModules
     * @return array<string, int>
     */
    public function handle(string $dashboardType, array $visibleModules): array
    {
        $includeAll = $dashboardType === 'super_admin';
        $statistics = [];

        foreach ($this->definitions() as $key => $definition) {
            if (! $includeAll && ! $this->isVisible($definition['modules'], $visibleModules)) {
                continue;
            }

            $statistics[$key] = (int) ($definition['summary'])()->total;
        }

        return $statistics;
    }

    /**
     * @param  list<string>  $requiredModules
     * @param  list<string>  $visibleModules
     */
    private function isVisible(array $requiredModules, array $visibleModules): bool
    {
        foreach ($requiredModules as $module) {
            if (in_array($module, $visibleModules, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{modules: list<string>, summary: callable(): DashboardKpiData}>
     */
    private function definitions(): array
    {
        return [
            'total_admins' => [
                'modules' => ['admin_management'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->adminSummary(),
            ],
            'total_customers' => [
                'modules' => ['customers'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->customerSummary(),
            ],
            'total_bookings' => [
                'modules' => ['bookings'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->bookingSummary(),
            ],
            'total_quotations' => [
                'modules' => ['quotations'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->quotationSummary(),
            ],
            'total_orders' => [
                'modules' => ['orders'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->orderSummary(),
            ],
            'total_store_orders' => [
                'modules' => ['store'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->storeOrderSummary(),
            ],
            'total_products' => [
                'modules' => ['store'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->inventorySummary(),
            ],
            'total_suppliers' => [
                'modules' => ['inventory'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->supplierSummary(),
            ],
            'total_purchase_orders' => [
                'modules' => ['inventory'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->purchaseOrderSummary(),
            ],
            'total_goods_receipts' => [
                'modules' => ['inventory'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->goodsReceiptSummary(),
            ],
            'total_payments' => [
                'modules' => ['payments'],
                'summary' => fn (): DashboardKpiData => $this->dashboardQueries->paymentSummary(),
            ],
        ];
    }
}
