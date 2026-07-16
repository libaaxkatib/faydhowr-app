<?php

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\GoodsReceipt;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\StoreOrder;
use App\Models\Supplier;
use Illuminate\Support\Facades\Cache;

class GetDashboardStatisticsAction
{
    public const CACHE_TTL_SECONDS = 300;

    /**
     * @param  list<string>  $visibleModules
     * @return array<string, int>
     */
    public function handle(Admin $admin, string $dashboardType, array $visibleModules): array
    {
        return Cache::remember(
            self::cacheKey($admin),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->compute($dashboardType, $visibleModules),
        );
    }

    public static function cacheKey(Admin|int $admin): string
    {
        $adminId = $admin instanceof Admin ? $admin->id : $admin;

        return "dashboard:{$adminId}";
    }

    public static function forgetFor(Admin|int $admin): void
    {
        Cache::forget(self::cacheKey($admin));
    }

    /**
     * @param  list<string>  $visibleModules
     * @return array<string, int>
     */
    private function compute(string $dashboardType, array $visibleModules): array
    {
        $includeAll = $dashboardType === 'super_admin';
        $statistics = [];

        foreach ($this->definitions() as $key => $definition) {
            if (! $includeAll && ! $this->isVisible($definition['modules'], $visibleModules)) {
                continue;
            }

            $statistics[$key] = ($definition['count'])();
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
     * @return array<string, array{modules: list<string>, count: callable(): int}>
     */
    private function definitions(): array
    {
        return [
            'total_admins' => [
                'modules' => ['admin_management'],
                'count' => fn (): int => Admin::query()->count(),
            ],
            'total_customers' => [
                'modules' => ['customers'],
                'count' => fn (): int => CustomerProfile::query()->count(),
            ],
            'total_bookings' => [
                'modules' => ['bookings'],
                'count' => fn (): int => Booking::query()->count(),
            ],
            'total_quotations' => [
                'modules' => ['quotations'],
                'count' => fn (): int => Quotation::query()->count(),
            ],
            'total_orders' => [
                'modules' => ['orders'],
                'count' => fn (): int => Order::query()->count(),
            ],
            'total_store_orders' => [
                'modules' => ['store'],
                'count' => fn (): int => StoreOrder::query()->count(),
            ],
            'total_products' => [
                'modules' => ['store'],
                'count' => fn (): int => Product::query()->count(),
            ],
            'total_suppliers' => [
                'modules' => ['inventory'],
                'count' => fn (): int => Supplier::query()->count(),
            ],
            'total_purchase_orders' => [
                'modules' => ['inventory'],
                'count' => fn (): int => PurchaseOrder::query()->count(),
            ],
            'total_goods_receipts' => [
                'modules' => ['inventory'],
                'count' => fn (): int => GoodsReceipt::query()->count(),
            ],
            'total_payments' => [
                'modules' => ['payments'],
                'count' => fn (): int => Payment::query()->count(),
            ],
        ];
    }
}
