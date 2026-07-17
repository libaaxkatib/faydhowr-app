<?php

namespace App\Providers;

use App\Contracts\Reports\Storage\ReportStorageInterface;
use App\Services\Dashboard\DashboardManager;
use App\Services\Dashboard\Widgets\BookingSummaryWidget;
use App\Services\Dashboard\Widgets\CustomerSummaryWidget;
use App\Services\Dashboard\Widgets\InventorySummaryWidget;
use App\Services\Dashboard\Widgets\OrderSummaryWidget;
use App\Services\Dashboard\Widgets\PaymentSummaryWidget;
use App\Services\Dashboard\Widgets\QuotationSummaryWidget;
use App\Services\Dashboard\Widgets\RevenueSummaryWidget;
use App\Services\Notification\Channels\EmailNotificationChannel;
use App\Services\Notification\Channels\InAppNotificationChannel;
use App\Services\Notification\Channels\SmsNotificationChannel;
use App\Services\Notification\NotificationChannelManager;
use App\Services\Payments\Gateways\ManualPaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Reports\Generators\BookingReportGenerator;
use App\Services\Reports\Generators\CustomerReportGenerator;
use App\Services\Reports\Generators\GoodsReceiptReportGenerator;
use App\Services\Reports\Generators\InventoryReportGenerator;
use App\Services\Reports\Generators\OrderReportGenerator;
use App\Services\Reports\Generators\PaymentReportGenerator;
use App\Services\Reports\Generators\PurchaseOrderReportGenerator;
use App\Services\Reports\Generators\QuotationReportGenerator;
use App\Services\Reports\Generators\StoreOrderReportGenerator;
use App\Services\Reports\Generators\SupplierReportGenerator;
use App\Services\Reports\ReportManager;
use App\Services\Reports\Storage\LocalReportStorage;
use App\Services\Reports\Storage\ReportStorageManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class, function (): PaymentGatewayManager {
            $manager = new PaymentGatewayManager;
            $manager->register('manual', new ManualPaymentGateway);

            return $manager;
        });

        $this->app->singleton(NotificationChannelManager::class, function (): NotificationChannelManager {
            $manager = new NotificationChannelManager;
            $manager->register('in_app', new InAppNotificationChannel);
            $manager->register('email', new EmailNotificationChannel);
            $manager->register('sms', new SmsNotificationChannel);

            return $manager;
        });

        $this->app->singleton(ReportManager::class, function (Application $app): ReportManager {
            return new ReportManager([
                $app->make(BookingReportGenerator::class),
                $app->make(QuotationReportGenerator::class),
                $app->make(OrderReportGenerator::class),
                $app->make(PaymentReportGenerator::class),
                $app->make(StoreOrderReportGenerator::class),
                $app->make(InventoryReportGenerator::class),
                $app->make(SupplierReportGenerator::class),
                $app->make(PurchaseOrderReportGenerator::class),
                $app->make(GoodsReceiptReportGenerator::class),
                $app->make(CustomerReportGenerator::class),
            ]);
        });

        $this->app->singleton(ReportStorageManager::class, function (Application $app): ReportStorageManager {
            $manager = new ReportStorageManager;
            $manager->register('local', $app->make(LocalReportStorage::class));

            return $manager;
        });

        $this->app->bind(
            ReportStorageInterface::class,
            fn (Application $app): ReportStorageInterface => $app->make(ReportStorageManager::class)->driver(),
        );

        $this->app->singleton(DashboardManager::class, function (Application $app): DashboardManager {
            return new DashboardManager([
                $app->make(BookingSummaryWidget::class),
                $app->make(QuotationSummaryWidget::class),
                $app->make(OrderSummaryWidget::class),
                $app->make(PaymentSummaryWidget::class),
                $app->make(RevenueSummaryWidget::class),
                $app->make(InventorySummaryWidget::class),
                $app->make(CustomerSummaryWidget::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth-register', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                Str::lower((string) $request->input('email')).'|'.$request->ip(),
            );
        });
    }
}
