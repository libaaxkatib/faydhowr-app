<?php

use App\Http\Controllers\Api\V1\Admin\Accounting\AccountingPeriodController;
use App\Http\Controllers\Api\V1\Admin\Accounting\BalanceSheetController;
use App\Http\Controllers\Api\V1\Admin\Accounting\ChartOfAccountController;
use App\Http\Controllers\Api\V1\Admin\Accounting\IncomeStatementController;
use App\Http\Controllers\Api\V1\Admin\Accounting\JournalEntryController;
use App\Http\Controllers\Api\V1\Admin\Accounting\LedgerBalanceController;
use App\Http\Controllers\Api\V1\Admin\Accounting\TrialBalanceController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\AdminPermissionController;
use App\Http\Controllers\Api\V1\Admin\ArchivedNotificationController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerActivityController as AdminCustomerActivityController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerAddressController as AdminCustomerAddressController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerAttachmentController as AdminCustomerAttachmentController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerNoteController as AdminCustomerNoteController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\NotificationTemplateController;
use App\Http\Controllers\Api\V1\Admin\NotificationTemplateTranslationController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\Reports\BookingReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\BookingReportSummaryController;
use App\Http\Controllers\Api\V1\Admin\Reports\CustomerReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\CustomerReportSummaryController;
use App\Http\Controllers\Api\V1\Admin\Reports\GoodsReceiptReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\InventoryReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\InventoryReportSummaryController;
use App\Http\Controllers\Api\V1\Admin\Reports\OrderReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\PaymentReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\PurchaseOrderReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\QuotationReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\ReportExportController;
use App\Http\Controllers\Api\V1\Admin\Reports\ReportExportDownloadController;
use App\Http\Controllers\Api\V1\Admin\Reports\RevenueReportSummaryController;
use App\Http\Controllers\Api\V1\Admin\Reports\StoreOrderReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\SupplierReportController;
use App\Http\Controllers\Api\V1\Admin\Settings\BackupController;
use App\Http\Controllers\Api\V1\Admin\Settings\BranchController;
use App\Http\Controllers\Api\V1\Admin\Settings\CompanyLogoController;
use App\Http\Controllers\Api\V1\Admin\Settings\SettingsAuditLogController;
use App\Http\Controllers\Api\V1\Admin\Settings\SettingsController;
use App\Http\Controllers\Api\V1\Admin\Settings\SmtpTestController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordRecoveryController;
use App\Http\Controllers\Api\V1\Auth\PhoneAuthController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use App\Http\Controllers\Api\V1\Cart\CartController;
use App\Http\Controllers\Api\V1\Checkout\CheckoutController;
use App\Http\Controllers\Api\V1\Customer\CustomerAddressController;
use App\Http\Controllers\Api\V1\Customer\CustomerProfileController;
use App\Http\Controllers\Api\V1\GoodsReceipt\GoodsReceiptController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Notification\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use App\Http\Controllers\Api\V1\Order\OrderLifecycleController;
use App\Http\Controllers\Api\V1\Payment\PaymentController;
use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Product\ProductController;
use App\Http\Controllers\Api\V1\Product\ProductImageController;
use App\Http\Controllers\Api\V1\PurchaseOrder\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Quotation\QuotationAcceptanceController;
use App\Http\Controllers\Api\V1\Quotation\QuotationController;
use App\Http\Controllers\Api\V1\Quotation\QuotationDiscussionController;
use App\Http\Controllers\Api\V1\StoreOrder\StoreOrderController;
use App\Http\Controllers\Api\V1\Supplier\SupplierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function (): void {
    Route::get('me', [AuthenticatedUserController::class, 'show'])
        ->middleware('auth:sanctum')
        ->name('api.v1.auth.me');

    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:auth-login')
        ->name('api.v1.auth.login');

    Route::post('logout', [LogoutController::class, 'store'])
        ->middleware('auth:sanctum')
        ->name('api.v1.auth.logout');

    Route::post('register', [RegistrationController::class, 'store'])
        ->middleware('throttle:auth-register')
        ->name('api.v1.auth.register');

    Route::post('phone/request', [PhoneAuthController::class, 'requestOtp'])
        ->middleware('throttle:auth-otp')
        ->name('api.v1.auth.phone.request');

    Route::post('phone/verify', [PhoneAuthController::class, 'verify'])
        ->middleware('throttle:auth-otp')
        ->name('api.v1.auth.phone.verify');

    Route::post('google', [GoogleAuthController::class, 'store'])
        ->middleware('throttle:auth-login')
        ->name('api.v1.auth.google');

    Route::post('forgot-password', [PasswordRecoveryController::class, 'forgot'])
        ->middleware('throttle:auth-recovery')
        ->name('api.v1.auth.forgot-password');

    Route::post('reset-password', [PasswordRecoveryController::class, 'reset'])
        ->middleware('throttle:auth-recovery')
        ->name('api.v1.auth.reset-password');
});

Route::prefix('v1/admin/auth')->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:auth-login')
        ->name('api.v1.admin.auth.login');

    Route::post('logout', [AdminAuthController::class, 'logout'])
        ->middleware(['auth:sanctum', 'admin'])
        ->name('api.v1.admin.auth.logout');

    Route::get('me', [AdminAuthController::class, 'me'])
        ->middleware(['auth:sanctum', 'admin'])
        ->name('api.v1.admin.auth.me');
});

Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'show'])
            ->middleware('permission:dashboard.view')
            ->name('api.v1.admin.dashboard.show');

        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:admins.manage')
            ->name('api.v1.admin.audit-logs.index');

        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('permission:roles.manage')
            ->name('api.v1.admin.permissions.index');

        Route::put('roles/{role}/permissions', [PermissionController::class, 'updateRolePermissions'])
            ->middleware('permission:roles.manage')
            ->name('api.v1.admin.roles.permissions.update');

        Route::get('admins', [AdminController::class, 'index'])
            ->middleware('permission:admins.manage')
            ->name('api.v1.admin.admins.index');

        Route::post('admins', [AdminController::class, 'store'])
            ->middleware('permission:admins.manage')
            ->name('api.v1.admin.admins.store');

        Route::get('admins/{admin}', [AdminController::class, 'show'])
            ->middleware('permission:admins.manage')
            ->name('api.v1.admin.admins.show');

        Route::put('admins/{admin}', [AdminController::class, 'update'])
            ->middleware('permission:admins.manage')
            ->name('api.v1.admin.admins.update');

        Route::delete('admins/{admin}', [AdminController::class, 'destroy'])
            ->middleware('permission:admins.manage')
            ->name('api.v1.admin.admins.destroy');

        Route::get('admins/{admin}/permissions', [AdminPermissionController::class, 'show'])
            ->middleware('permission:roles.manage')
            ->name('api.v1.admin.admins.permissions.show');

        Route::put('admins/{admin}/permissions', [AdminPermissionController::class, 'update'])
            ->middleware('permission:roles.manage')
            ->name('api.v1.admin.admins.permissions.update');

        Route::get('notification-templates', [NotificationTemplateController::class, 'index'])
            ->middleware('permission:notifications.manage')
            ->name('api.v1.admin.notification-templates.index');
        Route::get('notification-templates/{template}', [NotificationTemplateController::class, 'show'])
            ->middleware('permission:notifications.manage')
            ->whereNumber('template')
            ->name('api.v1.admin.notification-templates.show');
        Route::post('notification-templates', [NotificationTemplateController::class, 'store'])
            ->middleware('permission:notifications.manage')
            ->name('api.v1.admin.notification-templates.store');
        Route::put('notification-templates/{template}', [NotificationTemplateController::class, 'update'])
            ->middleware('permission:notifications.manage')
            ->whereNumber('template')
            ->name('api.v1.admin.notification-templates.update');

        Route::get('notification-templates/{template}/translations', [NotificationTemplateTranslationController::class, 'index'])
            ->middleware('permission:notifications.manage')
            ->whereNumber('template')
            ->name('api.v1.admin.notification-templates.translations.index');
        Route::post('notification-templates/{template}/translations', [NotificationTemplateTranslationController::class, 'store'])
            ->middleware('permission:notifications.manage')
            ->whereNumber('template')
            ->name('api.v1.admin.notification-templates.translations.store');
        Route::put('notification-templates/{template}/translations/{translation}', [NotificationTemplateTranslationController::class, 'update'])
            ->middleware('permission:notifications.manage')
            ->whereNumber('template')
            ->whereNumber('translation')
            ->name('api.v1.admin.notification-templates.translations.update');

        Route::get('archived-notifications', [ArchivedNotificationController::class, 'index'])
            ->middleware('permission:notifications.manage')
            ->name('api.v1.admin.archived-notifications.index');
    });

Route::prefix('v1/admin/reports')
    ->middleware(['auth:sanctum', 'admin', 'permission:reports.view'])
    ->group(function (): void {
        Route::post('bookings', [BookingReportController::class, 'store'])
            ->name('api.v1.admin.reports.bookings.store');
        Route::post('quotations', [QuotationReportController::class, 'store'])
            ->name('api.v1.admin.reports.quotations.store');
        Route::post('orders', [OrderReportController::class, 'store'])
            ->name('api.v1.admin.reports.orders.store');
        Route::post('payments', [PaymentReportController::class, 'store'])
            ->name('api.v1.admin.reports.payments.store');
        Route::post('store-orders', [StoreOrderReportController::class, 'store'])
            ->name('api.v1.admin.reports.store-orders.store');
        Route::post('inventory', [InventoryReportController::class, 'store'])
            ->name('api.v1.admin.reports.inventory.store');
        Route::post('suppliers', [SupplierReportController::class, 'store'])
            ->name('api.v1.admin.reports.suppliers.store');
        Route::post('purchase-orders', [PurchaseOrderReportController::class, 'store'])
            ->name('api.v1.admin.reports.purchase-orders.store');
        Route::post('goods-receipts', [GoodsReceiptReportController::class, 'store'])
            ->name('api.v1.admin.reports.goods-receipts.store');
        Route::post('customers', [CustomerReportController::class, 'store'])
            ->name('api.v1.admin.reports.customers.store');

        Route::get('revenue', [RevenueReportSummaryController::class, 'show'])
            ->name('api.v1.admin.reports.revenue.show');
        Route::get('revenue/pdf', [RevenueReportSummaryController::class, 'pdf'])
            ->name('api.v1.admin.reports.revenue.pdf');
        Route::get('revenue/excel', [RevenueReportSummaryController::class, 'excel'])
            ->name('api.v1.admin.reports.revenue.excel');

        Route::get('bookings', [BookingReportSummaryController::class, 'show'])
            ->name('api.v1.admin.reports.bookings.show');
        Route::get('bookings/pdf', [BookingReportSummaryController::class, 'pdf'])
            ->name('api.v1.admin.reports.bookings.pdf');
        Route::get('bookings/excel', [BookingReportSummaryController::class, 'excel'])
            ->name('api.v1.admin.reports.bookings.excel');

        Route::get('customers', [CustomerReportSummaryController::class, 'show'])
            ->name('api.v1.admin.reports.customers.show');
        Route::get('customers/pdf', [CustomerReportSummaryController::class, 'pdf'])
            ->name('api.v1.admin.reports.customers.pdf');
        Route::get('customers/excel', [CustomerReportSummaryController::class, 'excel'])
            ->name('api.v1.admin.reports.customers.excel');

        Route::get('inventory', [InventoryReportSummaryController::class, 'show'])
            ->name('api.v1.admin.reports.inventory.show');
        Route::get('inventory/pdf', [InventoryReportSummaryController::class, 'pdf'])
            ->name('api.v1.admin.reports.inventory.pdf');
        Route::get('inventory/excel', [InventoryReportSummaryController::class, 'excel'])
            ->name('api.v1.admin.reports.inventory.excel');

        Route::post('{report}/export', [ReportExportController::class, 'store'])
            ->whereNumber('report')
            ->name('api.v1.admin.reports.export.store');
    });

Route::prefix('v1/admin/accounting')
    ->middleware(['auth:sanctum', 'admin', 'permission:accounting.view'])
    ->group(function (): void {
        Route::get('accounts', [ChartOfAccountController::class, 'index'])
            ->name('api.v1.admin.accounting.accounts.index');

        Route::get('accounts/{account}/balance', [LedgerBalanceController::class, 'show'])
            ->whereNumber('account')
            ->name('api.v1.admin.accounting.accounts.balance');

        Route::get('journal-entries', [JournalEntryController::class, 'index'])
            ->name('api.v1.admin.accounting.journal-entries.index');

        Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])
            ->whereNumber('journalEntry')
            ->name('api.v1.admin.accounting.journal-entries.show');

        Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])
            ->whereNumber('journalEntry')
            ->name('api.v1.admin.accounting.journal-entries.post');

        Route::get('periods', [AccountingPeriodController::class, 'index'])
            ->name('api.v1.admin.accounting.periods.index');

        Route::post('periods', [AccountingPeriodController::class, 'store'])
            ->name('api.v1.admin.accounting.periods.store');

        Route::get('trial-balance', [TrialBalanceController::class, 'show'])
            ->name('api.v1.admin.accounting.trial-balance.show');

        Route::get('reports/income-statement', [IncomeStatementController::class, 'show'])
            ->name('api.v1.admin.accounting.reports.income-statement.show');

        Route::get('reports/balance-sheet', [BalanceSheetController::class, 'show'])
            ->name('api.v1.admin.accounting.reports.balance-sheet.show');
    });

Route::prefix('v1/admin/settings')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [SettingsController::class, 'index'])
            ->middleware('permission:settings.view')
            ->name('api.v1.admin.settings.index');

        Route::get('audit-logs', [SettingsAuditLogController::class, 'index'])
            ->middleware('permission:settings.view')
            ->name('api.v1.admin.settings.audit-logs.index');

        Route::post('company/logo', [CompanyLogoController::class, 'store'])
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.settings.company.logo.store');

        Route::post('smtp/test', [SmtpTestController::class, 'store'])
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.settings.smtp.test.store');

        Route::get('{category}', [SettingsController::class, 'show'])
            ->middleware('permission:settings.view')
            ->name('api.v1.admin.settings.show');

        Route::put('{category}', [SettingsController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.settings.update');

        Route::post('{category}/restore-defaults', [SettingsController::class, 'restoreDefaults'])
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.settings.restore-defaults');
    });

Route::prefix('v1/admin/branches')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [BranchController::class, 'index'])
            ->middleware('permission:settings.view')
            ->name('api.v1.admin.branches.index');

        Route::get('{branch}', [BranchController::class, 'show'])
            ->whereNumber('branch')
            ->middleware('permission:settings.view')
            ->name('api.v1.admin.branches.show');

        Route::patch('{branch}/activate', [BranchController::class, 'activate'])
            ->whereNumber('branch')
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.branches.activate');

        Route::patch('{branch}/default', [BranchController::class, 'makeDefault'])
            ->whereNumber('branch')
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.branches.default');
    });

Route::prefix('v1/admin/backups')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [BackupController::class, 'index'])
            ->middleware('permission:settings.view')
            ->name('api.v1.admin.backups.index');

        Route::post('', [BackupController::class, 'store'])
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.backups.store');

        Route::get('{backup}/download', [BackupController::class, 'download'])
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.backups.download');

        Route::post('{backup}/restore', [BackupController::class, 'restore'])
            ->middleware('permission:settings.manage')
            ->name('api.v1.admin.backups.restore');
    });

Route::prefix('v1/admin/customers')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [AdminCustomerController::class, 'index'])
            ->middleware('permission:customers.view')
            ->name('api.v1.admin.customers.index');

        Route::post('', [AdminCustomerController::class, 'store'])
            ->middleware('permission:customers.create')
            ->name('api.v1.admin.customers.store');

        Route::get('{customer}', [AdminCustomerController::class, 'show'])
            ->whereNumber('customer')
            ->middleware('permission:customers.view')
            ->name('api.v1.admin.customers.show');

        Route::put('{customer}', [AdminCustomerController::class, 'update'])
            ->whereNumber('customer')
            ->middleware('permission:customers.update')
            ->name('api.v1.admin.customers.update');

        Route::patch('{customer}/status', [AdminCustomerController::class, 'updateStatus'])
            ->whereNumber('customer')
            ->middleware('permission:customers.update')
            ->name('api.v1.admin.customers.status');

        Route::delete('{customer}', [AdminCustomerController::class, 'destroy'])
            ->whereNumber('customer')
            ->middleware('permission:customers.delete')
            ->name('api.v1.admin.customers.destroy');

        Route::post('{customer}/restore', [AdminCustomerController::class, 'restore'])
            ->whereNumber('customer')
            ->middleware('permission:customers.restore')
            ->name('api.v1.admin.customers.restore');

        Route::get('{customer}/timeline', [AdminCustomerActivityController::class, 'timeline'])
            ->whereNumber('customer')
            ->middleware('permission:customers.view')
            ->name('api.v1.admin.customers.timeline');

        Route::get('{customer}/activity-logs', [AdminCustomerActivityController::class, 'activityLogs'])
            ->whereNumber('customer')
            ->middleware('permission:customers.view')
            ->name('api.v1.admin.customers.activity-logs');

        Route::get('{customer}/addresses', [AdminCustomerAddressController::class, 'index'])
            ->whereNumber('customer')
            ->middleware('permission:customers.view')
            ->name('api.v1.admin.customers.addresses.index');

        Route::post('{customer}/addresses', [AdminCustomerAddressController::class, 'store'])
            ->whereNumber('customer')
            ->middleware('permission:customers.update')
            ->name('api.v1.admin.customers.addresses.store');

        Route::put('{customer}/addresses/{address}', [AdminCustomerAddressController::class, 'update'])
            ->whereNumber('customer')
            ->whereNumber('address')
            ->middleware('permission:customers.update')
            ->name('api.v1.admin.customers.addresses.update');

        Route::patch('{customer}/addresses/{address}/default', [AdminCustomerAddressController::class, 'setDefault'])
            ->whereNumber('customer')
            ->whereNumber('address')
            ->middleware('permission:customers.update')
            ->name('api.v1.admin.customers.addresses.default');

        Route::patch('{customer}/addresses/{address}/deactivate', [AdminCustomerAddressController::class, 'deactivate'])
            ->whereNumber('customer')
            ->whereNumber('address')
            ->middleware('permission:customers.update')
            ->name('api.v1.admin.customers.addresses.deactivate');

        Route::get('{customer}/notes', [AdminCustomerNoteController::class, 'index'])
            ->whereNumber('customer')
            ->middleware('permission:customers.notes')
            ->name('api.v1.admin.customers.notes.index');

        Route::post('{customer}/notes', [AdminCustomerNoteController::class, 'store'])
            ->whereNumber('customer')
            ->middleware('permission:customers.notes')
            ->name('api.v1.admin.customers.notes.store');

        Route::get('{customer}/attachments', [AdminCustomerAttachmentController::class, 'index'])
            ->whereNumber('customer')
            ->middleware('permission:customers.attachments')
            ->name('api.v1.admin.customers.attachments.index');

        Route::post('{customer}/attachments', [AdminCustomerAttachmentController::class, 'store'])
            ->whereNumber('customer')
            ->middleware('permission:customers.attachments')
            ->name('api.v1.admin.customers.attachments.store');

        Route::get('{customer}/attachments/{attachment}/download', [AdminCustomerAttachmentController::class, 'download'])
            ->whereNumber('customer')
            ->whereNumber('attachment')
            ->middleware('permission:customers.attachments')
            ->name('api.v1.admin.customers.attachments.download');

        Route::delete('{customer}/attachments/{attachment}', [AdminCustomerAttachmentController::class, 'destroy'])
            ->whereNumber('customer')
            ->whereNumber('attachment')
            ->middleware('permission:customers.attachments')
            ->name('api.v1.admin.customers.attachments.destroy');
    });

Route::prefix('v1/admin/report-exports')
    ->middleware(['auth:sanctum', 'admin', 'permission:reports.view'])
    ->group(function (): void {
        Route::get('', [ReportExportController::class, 'index'])
            ->name('api.v1.admin.report-exports.index');

        Route::get('{export}/download', [ReportExportDownloadController::class, 'show'])
            ->whereNumber('export')
            ->name('api.v1.admin.report-exports.download');
    });

Route::get('v1/customer/profile', [CustomerProfileController::class, 'show'])
    ->middleware('auth:sanctum')
    ->name('api.v1.customer.profile');

Route::patch('v1/customer/profile', [CustomerProfileController::class, 'update'])
    ->middleware('auth:sanctum')
    ->name('api.v1.customer.profile.update');

Route::prefix('v1/customer/addresses')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('/', [CustomerAddressController::class, 'index'])
            ->name('api.v1.customer.addresses.index');
        Route::post('/', [CustomerAddressController::class, 'store'])
            ->name('api.v1.customer.addresses.store');
        Route::get('{address}', [CustomerAddressController::class, 'show'])
            ->name('api.v1.customer.addresses.show');
        Route::patch('{address}', [CustomerAddressController::class, 'update'])
            ->name('api.v1.customer.addresses.update');
        Route::post('{address}/default', [CustomerAddressController::class, 'setDefault'])
            ->name('api.v1.customer.addresses.default');
        Route::post('{address}/inactive', [CustomerAddressController::class, 'inactive'])
            ->name('api.v1.customer.addresses.inactive');
        Route::post('{address}/reactivate', [CustomerAddressController::class, 'reactivate'])
            ->name('api.v1.customer.addresses.reactivate');
    });

Route::prefix('v1/cart')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('/', [CartController::class, 'show'])
            ->name('api.v1.cart.show');
        Route::post('items', [CartController::class, 'storeItem'])
            ->name('api.v1.cart.items.store');
        Route::patch('items/{item}', [CartController::class, 'updateItem'])
            ->whereNumber('item')
            ->name('api.v1.cart.items.update');
        Route::delete('items/{item}', [CartController::class, 'destroyItem'])
            ->whereNumber('item')
            ->name('api.v1.cart.items.destroy');
        Route::delete('/', [CartController::class, 'destroy'])
            ->name('api.v1.cart.destroy');
    });

Route::post('v1/checkout', [CheckoutController::class, 'store'])
    ->middleware('auth:sanctum')
    ->name('api.v1.checkout.store');

Route::prefix('v1/store-orders')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('/', [StoreOrderController::class, 'index'])
            ->name('api.v1.store-orders.index');
        Route::post('/', [StoreOrderController::class, 'store'])
            ->name('api.v1.store-orders.store');
        Route::get('{storeOrder}', [StoreOrderController::class, 'show'])
            ->whereNumber('storeOrder')
            ->name('api.v1.store-orders.show');
        Route::patch('{storeOrder}/cancel', [StoreOrderController::class, 'cancel'])
            ->whereNumber('storeOrder')
            ->name('api.v1.store-orders.cancel');
    });

Route::prefix('v1/bookings')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('/', [BookingController::class, 'index'])
            ->name('api.v1.bookings.index');
        Route::post('/', [BookingController::class, 'store'])
            ->name('api.v1.bookings.store');
        Route::get('{booking}', [BookingController::class, 'show'])
            ->name('api.v1.bookings.show');
        Route::post('{booking}/cancel', [BookingController::class, 'cancel'])
            ->name('api.v1.bookings.cancel');
    });

Route::prefix('v1/quotations')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('/', [QuotationController::class, 'index'])
            ->name('api.v1.quotations.index');
        Route::post('/', [QuotationController::class, 'store'])
            ->name('api.v1.quotations.store');
        Route::get('{quotation}/discussion', [QuotationDiscussionController::class, 'index'])
            ->name('api.v1.quotations.discussion.index');
        Route::post('{quotation}/discussion', [QuotationDiscussionController::class, 'store'])
            ->name('api.v1.quotations.discussion.store');
        Route::post('{quotation}/accept', [QuotationAcceptanceController::class, 'store'])
            ->name('api.v1.quotations.accept');
        Route::get('{quotation}', [QuotationController::class, 'show'])
            ->name('api.v1.quotations.show');
    });

Route::prefix('v1/orders')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::get('/', [OrderController::class, 'index'])
            ->name('api.v1.orders.index');
        Route::post('/', [OrderController::class, 'store'])
            ->name('api.v1.orders.store');
        Route::post('{order}/cancel', [OrderLifecycleController::class, 'cancel'])
            ->name('api.v1.orders.cancel');
        Route::get('{order}', [OrderController::class, 'show'])
            ->name('api.v1.orders.show');
    });

Route::get('v1/payments', [PaymentController::class, 'index'])
    ->middleware('auth:sanctum')
    ->name('api.v1.payments.index');
Route::get('v1/payments/{payment}', [PaymentController::class, 'show'])
    ->middleware('auth:sanctum')
    ->name('api.v1.payments.show');
Route::post('v1/payments/initialize', [PaymentController::class, 'initialize'])
    ->middleware('auth:sanctum')
    ->name('api.v1.payments.initialize');
Route::post('v1/payments/{payment}/process', [PaymentController::class, 'process'])
    ->middleware('auth:sanctum')
    ->name('api.v1.payments.process');
Route::post('v1/payments/webhook', PaymentWebhookController::class)
    ->name('api.v1.payments.webhook');

Route::get('v1/products', [ProductController::class, 'index'])
    ->name('api.v1.products.index');
Route::get('v1/products/{product}', [ProductController::class, 'show'])
    ->whereNumber('product')
    ->name('api.v1.products.show');

Route::post('v1/products', [ProductController::class, 'store'])
    ->middleware(['auth:sanctum', 'admin', 'permission:products.create'])
    ->name('api.v1.products.store');
Route::put('v1/products/{product}', [ProductController::class, 'update'])
    ->middleware(['auth:sanctum', 'admin', 'permission:products.update'])
    ->whereNumber('product')
    ->name('api.v1.products.update');
Route::delete('v1/products/{product}', [ProductController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'admin', 'permission:products.delete'])
    ->whereNumber('product')
    ->name('api.v1.products.destroy');

Route::post('v1/products/{product}/images', [ProductImageController::class, 'store'])
    ->middleware(['auth:sanctum', 'admin', 'permission:products.update'])
    ->whereNumber('product')
    ->name('api.v1.products.images.store');
Route::patch('v1/products/{product}/images/reorder', [ProductImageController::class, 'reorder'])
    ->middleware(['auth:sanctum', 'admin', 'permission:products.update'])
    ->whereNumber('product')
    ->name('api.v1.products.images.reorder');
Route::patch('v1/products/{product}/images/{image}/primary', [ProductImageController::class, 'primary'])
    ->middleware(['auth:sanctum', 'admin', 'permission:products.update'])
    ->whereNumber('product')
    ->whereNumber('image')
    ->name('api.v1.products.images.primary');
Route::delete('v1/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
    ->middleware(['auth:sanctum', 'admin', 'permission:products.update'])
    ->whereNumber('product')
    ->whereNumber('image')
    ->name('api.v1.products.images.destroy');

Route::middleware(['auth:sanctum', 'admin', 'permission:suppliers.manage'])->group(function (): void {
    Route::get('v1/suppliers', [SupplierController::class, 'index'])
        ->name('api.v1.suppliers.index');
    Route::get('v1/suppliers/{supplier}', [SupplierController::class, 'show'])
        ->whereNumber('supplier')
        ->name('api.v1.suppliers.show');
    Route::post('v1/suppliers', [SupplierController::class, 'store'])
        ->name('api.v1.suppliers.store');
    Route::put('v1/suppliers/{supplier}', [SupplierController::class, 'update'])
        ->whereNumber('supplier')
        ->name('api.v1.suppliers.update');
    Route::delete('v1/suppliers/{supplier}', [SupplierController::class, 'destroy'])
        ->whereNumber('supplier')
        ->name('api.v1.suppliers.destroy');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:purchase_orders.manage'])->group(function (): void {
    Route::get('v1/purchase-orders', [PurchaseOrderController::class, 'index'])
        ->name('api.v1.purchase-orders.index');
    Route::get('v1/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
        ->whereNumber('purchaseOrder')
        ->name('api.v1.purchase-orders.show');
    Route::post('v1/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->name('api.v1.purchase-orders.store');
    Route::put('v1/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
        ->whereNumber('purchaseOrder')
        ->name('api.v1.purchase-orders.update');
    Route::patch('v1/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])
        ->whereNumber('purchaseOrder')
        ->name('api.v1.purchase-orders.submit');
    Route::patch('v1/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
        ->whereNumber('purchaseOrder')
        ->name('api.v1.purchase-orders.approve');
    Route::patch('v1/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->whereNumber('purchaseOrder')
        ->name('api.v1.purchase-orders.cancel');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:goods_receipts.manage'])->group(function (): void {
    Route::get('v1/goods-receipts', [GoodsReceiptController::class, 'index'])
        ->name('api.v1.goods-receipts.index');
    Route::get('v1/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
        ->whereNumber('goodsReceipt')
        ->name('api.v1.goods-receipts.show');
    Route::post('v1/goods-receipts', [GoodsReceiptController::class, 'store'])
        ->name('api.v1.goods-receipts.store');
});

Route::middleware('auth:sanctum')->prefix('v1/notifications')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index'])
        ->name('api.v1.notifications.index');
    Route::get('unread-count', [NotificationController::class, 'unreadCount'])
        ->name('api.v1.notifications.unread-count');
    Route::patch('read-all', [NotificationController::class, 'markAllRead'])
        ->name('api.v1.notifications.read-all');
    Route::get('{notification}', [NotificationController::class, 'show'])
        ->whereNumber('notification')
        ->name('api.v1.notifications.show');
    Route::patch('{notification}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notification')
        ->name('api.v1.notifications.read');
});

Route::middleware('auth:sanctum')->prefix('v1/notification-preferences')->group(function (): void {
    Route::get('/', [NotificationPreferenceController::class, 'index'])
        ->name('api.v1.notification-preferences.index');
    Route::put('/', [NotificationPreferenceController::class, 'update'])
        ->name('api.v1.notification-preferences.update');
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
