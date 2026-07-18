<?php

namespace App\Providers;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Contracts\Accounting\Repositories\AccountingPeriodRepositoryInterface;
use App\Contracts\Accounting\Repositories\AccountRepositoryInterface;
use App\Contracts\Accounting\Repositories\FinancialReportRepositoryInterface;
use App\Contracts\Accounting\Repositories\JournalEntryRepositoryInterface;
use App\Contracts\Accounting\Repositories\LedgerRepositoryInterface;
use App\Contracts\Accounting\Repositories\TrialBalanceRepositoryInterface;
use App\Contracts\Accounting\Services\AccountingPeriodServiceInterface;
use App\Contracts\Accounting\Services\ChartOfAccountServiceInterface;
use App\Contracts\Accounting\Services\FinancialReportServiceInterface;
use App\Contracts\Accounting\Services\JournalEntryServiceInterface;
use App\Contracts\Accounting\Services\JournalPostingServiceInterface;
use App\Contracts\Accounting\Services\LedgerServiceInterface;
use App\Contracts\Accounting\Services\TrialBalanceServiceInterface;
use App\Contracts\Auth\GoogleIdTokenVerifierInterface;
use App\Contracts\Auth\Repositories\PasswordResetTokenRepositoryInterface;
use App\Contracts\Auth\Repositories\PhoneOtpRepositoryInterface;
use App\Contracts\Auth\Services\OtpServiceInterface;
use App\Contracts\Customer\Repositories\CustomerActivityRepositoryInterface;
use App\Contracts\Customer\Repositories\CustomerAddressRepositoryInterface;
use App\Contracts\Customer\Repositories\CustomerAttachmentRepositoryInterface;
use App\Contracts\Customer\Repositories\CustomerNoteRepositoryInterface;
use App\Contracts\Customer\Repositories\CustomerRepositoryInterface;
use App\Contracts\Customer\Services\AddressServiceInterface;
use App\Contracts\Customer\Services\AttachmentServiceInterface;
use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Contracts\Customer\Services\CustomerServiceInterface;
use App\Contracts\Customer\Services\NoteServiceInterface;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Contracts\Dashboard\DashboardMetadataBuilderInterface;
use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\Contracts\Dashboard\DashboardWidgetRegistryInterface;
use App\Contracts\Reports\Excel\ExcelReportGeneratorInterface;
use App\Contracts\Reports\Pdf\PdfReportGeneratorInterface;
use App\Contracts\Reports\ReportManagerInterface;
use App\Contracts\Reports\Services\BookingReportServiceInterface;
use App\Contracts\Reports\Services\CustomerReportServiceInterface;
use App\Contracts\Reports\Services\InventoryReportServiceInterface;
use App\Contracts\Reports\Services\RevenueReportServiceInterface;
use App\Contracts\Reports\Storage\ReportStorageInterface;
use App\Contracts\Settings\Repositories\BranchRepositoryInterface;
use App\Contracts\Settings\Repositories\SettingsAuditRepositoryInterface;
use App\Contracts\Settings\Repositories\SystemSettingRepositoryInterface;
use App\Contracts\Settings\Services\AuditServiceInterface;
use App\Contracts\Settings\Services\BackupServiceInterface;
use App\Contracts\Settings\Services\BranchServiceInterface;
use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\Contracts\Sms\SmsSenderInterface;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\StoreOrder;
use App\Observers\Customer\CustomerCommercialActivityObserver;
use App\Repositories\Accounting\AccountingPeriodRepository;
use App\Repositories\Accounting\AccountRepository;
use App\Repositories\Accounting\FinancialReportRepository;
use App\Repositories\Accounting\JournalEntryRepository;
use App\Repositories\Accounting\LedgerRepository;
use App\Repositories\Accounting\TrialBalanceRepository;
use App\Repositories\Auth\PasswordResetTokenRepository;
use App\Repositories\Auth\PhoneOtpRepository;
use App\Repositories\Customer\CustomerActivityRepository;
use App\Repositories\Customer\CustomerAddressRepository;
use App\Repositories\Customer\CustomerAttachmentRepository;
use App\Repositories\Customer\CustomerNoteRepository;
use App\Repositories\Customer\CustomerRepository;
use App\Repositories\Settings\BranchRepository;
use App\Repositories\Settings\SettingsAuditRepository;
use App\Repositories\Settings\SystemSettingRepository;
use App\Services\Accounting\AccountingManager;
use App\Services\Accounting\Services\AccountingPeriodService;
use App\Services\Accounting\Services\ChartOfAccountService;
use App\Services\Accounting\Services\FinancialReportService;
use App\Services\Accounting\Services\JournalEntryService;
use App\Services\Accounting\Services\JournalPostingService;
use App\Services\Accounting\Services\LedgerService;
use App\Services\Accounting\Services\TrialBalanceService;
use App\Services\Auth\GoogleTokenInfoVerifier;
use App\Services\Auth\OtpService;
use App\Services\Customer\AddressService;
use App\Services\Customer\AttachmentService;
use App\Services\Customer\CustomerActivityService;
use App\Services\Customer\CustomerService;
use App\Services\Customer\NoteService;
use App\Services\Dashboard\DashboardCacheInvalidator;
use App\Services\Dashboard\DashboardManager;
use App\Services\Dashboard\DashboardMetadataBuilder;
use App\Services\Dashboard\DashboardQueryService;
use App\Services\Dashboard\DashboardWidgetRegistry;
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
use App\Services\Reports\Excel\ExcelReportGenerator;
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
use App\Services\Reports\Pdf\PdfReportGenerator;
use App\Services\Reports\ReportManager;
use App\Services\Reports\Services\BookingReportService;
use App\Services\Reports\Services\CustomerReportService;
use App\Services\Reports\Services\InventoryReportService;
use App\Services\Reports\Services\RevenueReportService;
use App\Services\Reports\Storage\LocalReportStorage;
use App\Services\Reports\Storage\ReportStorageManager;
use App\Services\Settings\AuditService;
use App\Services\Settings\BackupService;
use App\Services\Settings\BranchService;
use App\Services\Settings\SettingsService;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\NullSmsSender;
use App\Services\Sms\SmsSenderManager;
use App\Support\Customer\CustomerCodeGenerator;
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

        $this->app->singleton(SmsSenderManager::class, function (): SmsSenderManager {
            $manager = new SmsSenderManager;
            $manager->register('log', new LogSmsSender);
            $manager->register('null', new NullSmsSender);

            return $manager;
        });

        $this->app->bind(
            SmsSenderInterface::class,
            fn (Application $app): SmsSenderInterface => $app->make(SmsSenderManager::class)->driver(),
        );

        $this->app->bind(PhoneOtpRepositoryInterface::class, PhoneOtpRepository::class);

        $this->app->bind(PasswordResetTokenRepositoryInterface::class, PasswordResetTokenRepository::class);

        $this->app->singleton(OtpServiceInterface::class, OtpService::class);

        $this->app->singleton(GoogleIdTokenVerifierInterface::class, GoogleTokenInfoVerifier::class);

        $this->app->singleton(AccountRepositoryInterface::class, AccountRepository::class);

        $this->app->singleton(JournalEntryRepositoryInterface::class, JournalEntryRepository::class);

        $this->app->singleton(LedgerRepositoryInterface::class, LedgerRepository::class);

        $this->app->singleton(AccountingPeriodRepositoryInterface::class, AccountingPeriodRepository::class);

        $this->app->singleton(AccountingPeriodServiceInterface::class, AccountingPeriodService::class);

        $this->app->singleton(TrialBalanceRepositoryInterface::class, TrialBalanceRepository::class);

        $this->app->singleton(TrialBalanceServiceInterface::class, TrialBalanceService::class);

        $this->app->singleton(ChartOfAccountServiceInterface::class, ChartOfAccountService::class);

        $this->app->singleton(JournalPostingServiceInterface::class, JournalPostingService::class);

        $this->app->singleton(JournalEntryServiceInterface::class, JournalEntryService::class);

        $this->app->singleton(LedgerServiceInterface::class, LedgerService::class);

        $this->app->singleton(FinancialReportRepositoryInterface::class, FinancialReportRepository::class);

        $this->app->singleton(FinancialReportServiceInterface::class, FinancialReportService::class);

        $this->app->singleton(AccountingManagerInterface::class, AccountingManager::class);

        $this->app->bind(SystemSettingRepositoryInterface::class, SystemSettingRepository::class);

        $this->app->bind(BranchRepositoryInterface::class, BranchRepository::class);

        $this->app->bind(SettingsAuditRepositoryInterface::class, SettingsAuditRepository::class);

        $this->app->singleton(AuditServiceInterface::class, AuditService::class);

        $this->app->singleton(SettingsServiceInterface::class, SettingsService::class);

        $this->app->singleton(BranchServiceInterface::class, BranchService::class);

        $this->app->singleton(BackupServiceInterface::class, BackupService::class);

        $this->app->singleton(CustomerCodeGenerator::class);

        $this->app->bind(CustomerRepositoryInterface::class, CustomerRepository::class);
        $this->app->bind(CustomerAddressRepositoryInterface::class, CustomerAddressRepository::class);
        $this->app->bind(CustomerNoteRepositoryInterface::class, CustomerNoteRepository::class);
        $this->app->bind(CustomerAttachmentRepositoryInterface::class, CustomerAttachmentRepository::class);
        $this->app->bind(CustomerActivityRepositoryInterface::class, CustomerActivityRepository::class);

        $this->app->singleton(CustomerActivityServiceInterface::class, CustomerActivityService::class);
        $this->app->singleton(CustomerServiceInterface::class, CustomerService::class);
        $this->app->singleton(AddressServiceInterface::class, AddressService::class);
        $this->app->singleton(NoteServiceInterface::class, NoteService::class);
        $this->app->singleton(AttachmentServiceInterface::class, AttachmentService::class);

        $this->app->singleton(RevenueReportServiceInterface::class, RevenueReportService::class);

        $this->app->singleton(BookingReportServiceInterface::class, BookingReportService::class);

        $this->app->singleton(CustomerReportServiceInterface::class, CustomerReportService::class);

        $this->app->singleton(InventoryReportServiceInterface::class, InventoryReportService::class);

        $this->app->singleton(PdfReportGeneratorInterface::class, PdfReportGenerator::class);

        $this->app->singleton(ExcelReportGeneratorInterface::class, ExcelReportGenerator::class);

        $this->app->singleton(ReportManager::class, function (Application $app): ReportManager {
            return new ReportManager(
                $app->make(RevenueReportServiceInterface::class),
                $app->make(BookingReportServiceInterface::class),
                $app->make(CustomerReportServiceInterface::class),
                $app->make(InventoryReportServiceInterface::class),
                [
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
                ],
            );
        });

        $this->app->singleton(
            ReportManagerInterface::class,
            fn (Application $app): ReportManagerInterface => $app->make(ReportManager::class),
        );

        $this->app->singleton(ReportStorageManager::class, function (Application $app): ReportStorageManager {
            $manager = new ReportStorageManager;
            $manager->register('local', $app->make(LocalReportStorage::class));

            return $manager;
        });

        $this->app->bind(
            ReportStorageInterface::class,
            fn (Application $app): ReportStorageInterface => $app->make(ReportStorageManager::class)->driver(),
        );

        $this->app->singleton(DashboardCacheInvalidatorInterface::class, DashboardCacheInvalidator::class);

        $this->app->singleton(DashboardQueryServiceInterface::class, DashboardQueryService::class);

        $this->app->singleton(DashboardMetadataBuilderInterface::class, DashboardMetadataBuilder::class);

        $this->app->singleton(
            DashboardWidgetRegistryInterface::class,
            function (Application $app): DashboardWidgetRegistryInterface {
                return new DashboardWidgetRegistry([
                    $app->make(BookingSummaryWidget::class),
                    $app->make(QuotationSummaryWidget::class),
                    $app->make(OrderSummaryWidget::class),
                    $app->make(PaymentSummaryWidget::class),
                    $app->make(RevenueSummaryWidget::class),
                    $app->make(InventorySummaryWidget::class),
                    $app->make(CustomerSummaryWidget::class),
                ]);
            },
        );

        $this->app->singleton(DashboardManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $observer = $this->app->make(CustomerCommercialActivityObserver::class);
        Booking::observe($observer);
        Quotation::observe($observer);
        StoreOrder::observe($observer);
        Payment::observe($observer);

        RateLimiter::for('auth-register', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('auth-login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                Str::lower((string) $request->input('email')).'|'.$request->ip(),
            );
        });

        RateLimiter::for('auth-otp', function (Request $request): Limit {
            return Limit::perMinute(10)->by(
                (string) $request->input('phone').'|'.$request->ip(),
            );
        });

        RateLimiter::for('auth-recovery', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                Str::lower((string) ($request->input('email') ?? $request->input('phone'))).'|'.$request->ip(),
            );
        });
    }
}
