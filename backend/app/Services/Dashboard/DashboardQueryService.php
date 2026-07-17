<?php

namespace App\Services\Dashboard;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Dashboard\DashboardKpiData;
use App\Enums\DashboardDateFilter;
use App\Models\Admin;
use App\Repositories\Reports\BookingReportRepository;
use App\Repositories\Reports\CustomerReportRepository;
use App\Repositories\Reports\GoodsReceiptReportRepository;
use App\Repositories\Reports\InventoryReportRepository;
use App\Repositories\Reports\OrderReportRepository;
use App\Repositories\Reports\PaymentReportRepository;
use App\Repositories\Reports\PurchaseOrderReportRepository;
use App\Repositories\Reports\QuotationReportRepository;
use App\Repositories\Reports\StoreOrderReportRepository;
use App\Repositories\Reports\SupplierReportRepository;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Centralized dashboard query layer. Owns every repository dependency so that
 * widgets never touch repositories or the database. All totals are computed by
 * the existing report repositories; this service contains no query-building or
 * presentation logic.
 */
class DashboardQueryService implements DashboardQueryServiceInterface
{
    public const CACHE_TTL_SECONDS = 300;

    private ?DashboardDateFilter $activeFilter = null;

    private ?CarbonImmutable $dateFrom = null;

    private ?CarbonImmutable $dateTo = null;

    public function __construct(
        private BookingReportRepository $bookingRepository,
        private QuotationReportRepository $quotationRepository,
        private OrderReportRepository $orderRepository,
        private PaymentReportRepository $paymentRepository,
        private InventoryReportRepository $inventoryRepository,
        private CustomerReportRepository $customerRepository,
        private StoreOrderReportRepository $storeOrderRepository,
        private SupplierReportRepository $supplierRepository,
        private PurchaseOrderReportRepository $purchaseOrderRepository,
        private GoodsReceiptReportRepository $goodsReceiptRepository,
        private DashboardCacheInvalidatorInterface $cacheInvalidator,
    ) {}

    public function applyDateFilter(
        ?DashboardDateFilter $filter,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): void {
        $this->activeFilter = $filter;
        [$this->dateFrom, $this->dateTo] = $this->resolveDateRange($filter, $startDate, $endDate);
    }

    public function bookingSummary(): DashboardKpiData
    {
        return $this->rememberSummary('bookings', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->bookingRepository->summary($this->filters())['total_records'],
            label: 'Bookings',
            unit: 'records',
        ));
    }

    public function quotationSummary(): DashboardKpiData
    {
        return $this->rememberSummary('quotations', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->quotationRepository->summary($this->filters())['total_records'],
            label: 'Quotations',
            unit: 'records',
        ));
    }

    public function orderSummary(): DashboardKpiData
    {
        return $this->rememberSummary('orders', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->orderRepository->summary($this->filters())['total_records'],
            label: 'Orders',
            unit: 'records',
        ));
    }

    public function paymentSummary(): DashboardKpiData
    {
        return $this->rememberSummary('payments', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->paymentRepository->summary($this->filters())['total_records'],
            label: 'Payments',
            unit: 'records',
        ));
    }

    /**
     * Total revenue is the sum of all payment amounts, as computed by the
     * payment report repository.
     */
    public function revenueSummary(): DashboardKpiData
    {
        return $this->rememberSummary('revenue', fn (): DashboardKpiData => $this->kpi(
            total: (float) $this->paymentRepository->summary($this->filters())['total_amount'],
            label: 'Revenue',
            unit: 'currency',
        ));
    }

    public function inventorySummary(): DashboardKpiData
    {
        return $this->rememberSummary('inventory', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->inventoryRepository->summary($this->filters())['total_records'],
            label: 'Products',
            unit: 'records',
        ));
    }

    public function customerSummary(): DashboardKpiData
    {
        return $this->rememberSummary('customers', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->customerRepository->summary($this->filters())['total_records'],
            label: 'Customers',
            unit: 'records',
        ));
    }

    public function storeOrderSummary(): DashboardKpiData
    {
        return $this->rememberSummary('store_orders', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->storeOrderRepository->summary($this->filters())['total_records'],
            label: 'Store Orders',
            unit: 'records',
        ));
    }

    public function supplierSummary(): DashboardKpiData
    {
        return $this->rememberSummary('suppliers', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->supplierRepository->summary($this->filters())['total_records'],
            label: 'Suppliers',
            unit: 'records',
        ));
    }

    public function purchaseOrderSummary(): DashboardKpiData
    {
        return $this->rememberSummary('purchase_orders', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->purchaseOrderRepository->summary($this->filters())['total_records'],
            label: 'Purchase Orders',
            unit: 'records',
        ));
    }

    public function goodsReceiptSummary(): DashboardKpiData
    {
        return $this->rememberSummary('goods_receipts', fn (): DashboardKpiData => $this->kpi(
            total: (int) $this->goodsReceiptRepository->summary($this->filters())['total_records'],
            label: 'Goods Receipts',
            unit: 'records',
        ));
    }

    /**
     * No admin report repository exists, so this is the single intentional
     * Eloquent count (not raw SQL) owned by the query service. It honors the
     * same date range and cache strategy as every other summary.
     */
    public function adminSummary(): DashboardKpiData
    {
        return $this->rememberSummary('admins', fn (): DashboardKpiData => $this->kpi(
            total: Admin::query()
                ->when($this->dateFrom !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $this->dateFrom))
                ->when($this->dateTo !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $this->dateTo))
                ->count(),
            label: 'Admins',
            unit: 'records',
        ));
    }

    /**
     * The KPI payload contract shared by every widget: the raw total, a stable
     * label and unit, and the ISO-8601 timestamp of when the payload was
     * generated. Cached payloads keep their original generation timestamp.
     */
    private function kpi(int|float $total, string $label, string $unit): DashboardKpiData
    {
        return new DashboardKpiData(
            total: $total,
            label: $label,
            unit: $unit,
            updatedAt: CarbonImmutable::now()->toIso8601String(),
        );
    }

    /**
     * Cache each summary for five minutes. The key incorporates the active
     * date filter and the resolved date range, so different filters and
     * different custom ranges never share an entry.
     *
     * @param  Closure(): DashboardKpiData  $loader
     */
    private function rememberSummary(string $summary, Closure $loader): DashboardKpiData
    {
        $cacheKey = $this->cacheKey($summary);

        $this->cacheInvalidator->record($cacheKey);

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, $loader);
    }

    public function cacheKey(string $summary): string
    {
        return sprintf(
            'dashboard:widgets:%s:%s:%s:%s',
            $summary,
            $this->activeFilter?->value ?? 'all_time',
            $this->dateFrom?->format('YmdHis') ?? '-',
            $this->dateTo?->format('YmdHis') ?? '-',
        );
    }

    /**
     * Filter creation is owned exclusively by this service; when no date
     * filter has been applied, all-time totals are returned.
     */
    private function filters(): NormalizedReportFilters
    {
        return new NormalizedReportFilters(
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
        );
    }

    /**
     * Date-range resolution lives on the DashboardDateFilter enum so the
     * reports module resolves identical ranges; a null filter means all-time.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    private function resolveDateRange(
        ?DashboardDateFilter $filter,
        ?CarbonImmutable $startDate,
        ?CarbonImmutable $endDate,
    ): array {
        return $filter?->dateRange($startDate, $endDate) ?? [null, null];
    }
}
