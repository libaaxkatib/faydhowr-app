<?php

use App\Http\Controllers\Api\V1\Admin\Accounting\AccountingPeriodController;
use App\Http\Controllers\Api\V1\Admin\Accounting\BalanceSheetController;
use App\Http\Controllers\Api\V1\Admin\Accounting\ChartOfAccountController;
use App\Http\Controllers\Api\V1\Admin\Accounting\IncomeStatementController;
use App\Http\Controllers\Api\V1\Admin\Accounting\JournalEntryController;
use App\Http\Controllers\Api\V1\Admin\Accounting\LedgerBalanceController;
use App\Http\Controllers\Api\V1\Admin\Accounting\TrialBalanceController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Admin\AdminPasswordController;
use App\Http\Controllers\Api\V1\Admin\AdminPermissionController;
use App\Http\Controllers\Api\V1\Admin\ArchivedNotificationController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\Bookings\BookingController as AdminBookingController;
use App\Http\Controllers\Api\V1\Admin\Content\BeforeAfterItemController as AdminBeforeAfterItemController;
use App\Http\Controllers\Api\V1\Admin\Content\FaqController as AdminFaqController;
use App\Http\Controllers\Api\V1\Admin\Content\HeroBannerController as AdminHeroBannerController;
use App\Http\Controllers\Api\V1\Admin\Content\ServiceFeaturedController as AdminServiceFeaturedController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerActivityController as AdminCustomerActivityController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerAddressController as AdminCustomerAddressController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerAttachmentController as AdminCustomerAttachmentController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\V1\Admin\Customers\CustomerNoteController as AdminCustomerNoteController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\Hr\AssignmentSuccessorOptionsController;
use App\Http\Controllers\Api\V1\Admin\Hr\ClientCompanyAgreementController as AdminClientCompanyAgreementController;
use App\Http\Controllers\Api\V1\Admin\Hr\ClientCompanyController as AdminClientCompanyController;
use App\Http\Controllers\Api\V1\Admin\Hr\CompanyEmployeeController as AdminCompanyEmployeeController;
use App\Http\Controllers\Api\V1\Admin\Hr\DepartmentController as AdminDepartmentController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeAttendanceController as AdminEmployeeAttendanceController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeCategoryController as AdminEmployeeCategoryController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeContractController as AdminEmployeeContractController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeDocumentCategoryController as AdminEmployeeDocumentCategoryController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeDocumentController as AdminEmployeeDocumentController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeGuarantorController as AdminEmployeeGuarantorController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeePayrollController as AdminEmployeePayrollController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeePracticalAssessmentController as AdminEmployeePracticalAssessmentController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeProfilePictureController as AdminEmployeeProfilePictureController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeRecordsController as AdminEmployeeRecordsController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeSeparationController as AdminEmployeeSeparationController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeStatusController as AdminEmployeeStatusController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeUniformController as AdminEmployeeUniformController;
use App\Http\Controllers\Api\V1\Admin\Hr\EmployeeWorkAssignmentController as AdminEmployeeWorkAssignmentController;
use App\Http\Controllers\Api\V1\Admin\Hr\FacilityComplaintController as AdminFacilityComplaintController;
use App\Http\Controllers\Api\V1\Admin\Hr\FacilityDeepCleaningController as AdminFacilityDeepCleaningController;
use App\Http\Controllers\Api\V1\Admin\Hr\FacilityInspectionController as AdminFacilityInspectionController;
use App\Http\Controllers\Api\V1\Admin\Hr\FacilityReportController as AdminFacilityReportController;
use App\Http\Controllers\Api\V1\Admin\Hr\HrDashboardController as AdminHrDashboardController;
use App\Http\Controllers\Api\V1\Admin\Hr\HrPolicyController as AdminHrPolicyController;
use App\Http\Controllers\Api\V1\Admin\Hr\HrReportsController as AdminHrReportsController;
use App\Http\Controllers\Api\V1\Admin\Hr\ImportController as AdminHrImportController;
use App\Http\Controllers\Api\V1\Admin\Hr\MonthlyPaymentReportController as AdminMonthlyPaymentReportController;
use App\Http\Controllers\Api\V1\Admin\Hr\OfficeStaffController as AdminOfficeStaffController;
use App\Http\Controllers\Api\V1\Admin\Hr\PayrollCorrectionController as AdminPayrollCorrectionController;
use App\Http\Controllers\Api\V1\Admin\Hr\PayrollRunController as AdminPayrollRunController;
use App\Http\Controllers\Api\V1\Admin\Hr\PositionController as AdminPositionController;
use App\Http\Controllers\Api\V1\Admin\Hr\PracticalBatchController as AdminPracticalBatchController;
use App\Http\Controllers\Api\V1\Admin\Hr\TemporaryReplacementController as AdminTemporaryReplacementController;
use App\Http\Controllers\Api\V1\Admin\Hr\TemporaryReplacementWorkdayController as AdminTemporaryReplacementWorkdayController;
use App\Http\Controllers\Api\V1\Admin\Hr\TrainingBatchController as AdminTrainingBatchController;
use App\Http\Controllers\Api\V1\Admin\Hr\WorkforceRequestController as AdminWorkforceRequestController;
use App\Http\Controllers\Api\V1\Admin\Hr\WorkLocationController as AdminWorkLocationController;
use App\Http\Controllers\Api\V1\Admin\Marketing\CommissionController as AdminMarketingCommissionController;
use App\Http\Controllers\Api\V1\Admin\Marketing\FollowUpController as AdminMarketingFollowUpController;
use App\Http\Controllers\Api\V1\Admin\Marketing\MarketingDashboardController as AdminMarketingDashboardController;
use App\Http\Controllers\Api\V1\Admin\Marketing\MarketingEmployeeController as AdminMarketingEmployeeController;
use App\Http\Controllers\Api\V1\Admin\Marketing\MarketingQuotationController as AdminMarketingQuotationController;
use App\Http\Controllers\Api\V1\Admin\Marketing\MarketingRecordController as AdminMarketingRecordController;
use App\Http\Controllers\Api\V1\Admin\Marketing\MarketingReportsController as AdminMarketingReportsController;
use App\Http\Controllers\Api\V1\Admin\Marketing\MarketingTeamController as AdminMarketingTeamController;
use App\Http\Controllers\Api\V1\Admin\Marketing\ProjectController as AdminMarketingProjectController;
use App\Http\Controllers\Api\V1\Admin\Marketing\XarunController as AdminMarketingXarunController;
use App\Http\Controllers\Api\V1\Admin\NotificationTemplateController;
use App\Http\Controllers\Api\V1\Admin\NotificationTemplateTranslationController;
use App\Http\Controllers\Api\V1\Admin\Payments\PaymentController as AdminPaymentController;
use App\Http\Controllers\Api\V1\Admin\PermissionController;
use App\Http\Controllers\Api\V1\Admin\Quotations\QuotationController as AdminQuotationController;
use App\Http\Controllers\Api\V1\Admin\Reconciliation\DataIssueController;
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
use App\Http\Controllers\Api\V1\Admin\Reviews\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\V1\Admin\Settings\BackupController;
use App\Http\Controllers\Api\V1\Admin\Settings\BranchController;
use App\Http\Controllers\Api\V1\Admin\Settings\CompanyLogoController;
use App\Http\Controllers\Api\V1\Admin\Settings\SettingsAuditLogController;
use App\Http\Controllers\Api\V1\Admin\Settings\SettingsController;
use App\Http\Controllers\Api\V1\Admin\Settings\SmtpTestController;
use App\Http\Controllers\Api\V1\Admin\StoreOrders\StoreOrderController as AdminStoreOrderController;
use App\Http\Controllers\Api\V1\Admin\TranslationOverrideController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordRecoveryController;
use App\Http\Controllers\Api\V1\Auth\PhoneAuthController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use App\Http\Controllers\Api\V1\Cart\CartController;
use App\Http\Controllers\Api\V1\Catalog\ServiceCategoryController as CatalogServiceCategoryController;
use App\Http\Controllers\Api\V1\Catalog\ServiceController as CatalogServiceController;
use App\Http\Controllers\Api\V1\Catalog\ServiceReviewController as CatalogServiceReviewController;
use App\Http\Controllers\Api\V1\Checkout\CheckoutController;
use App\Http\Controllers\Api\V1\Customer\CustomerAddressController;
use App\Http\Controllers\Api\V1\Customer\CustomerProfileController;
use App\Http\Controllers\Api\V1\Devices\DeviceController;
use App\Http\Controllers\Api\V1\Favorites\FavoriteController;
use App\Http\Controllers\Api\V1\GoodsReceipt\GoodsReceiptController;
use App\Http\Controllers\Api\V1\Home\HomeController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Notification\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use App\Http\Controllers\Api\V1\Order\OrderLifecycleController;
use App\Http\Controllers\Api\V1\Payment\PaymentController;
use App\Http\Controllers\Api\V1\Payment\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Product\ProductController;
use App\Http\Controllers\Api\V1\Product\ProductImageController;
use App\Http\Controllers\Api\V1\Product\ProductSearchController;
use App\Http\Controllers\Api\V1\PurchaseOrder\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Quotation\QuotationAcceptanceController;
use App\Http\Controllers\Api\V1\Quotation\QuotationAttachmentController;
use App\Http\Controllers\Api\V1\Quotation\QuotationController;
use App\Http\Controllers\Api\V1\Quotation\QuotationDiscussionController;
use App\Http\Controllers\Api\V1\Quotation\QuotationRevisionController;
use App\Http\Controllers\Api\V1\Quotation\QuotationTimelineController;
use App\Http\Controllers\Api\V1\Reviews\ReviewController;
use App\Http\Controllers\Api\V1\Search\SearchController;
use App\Http\Controllers\Api\V1\StoreOrder\StoreOrderController;
use App\Http\Controllers\Api\V1\Supplier\SupplierController;
use App\Http\Controllers\Api\V1\Uploads\UploadController;
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

Route::prefix('v1')->middleware('throttle:public-catalog')->group(function (): void {
    Route::get('home', [HomeController::class, 'index'])
        ->name('api.v1.home.index');

    Route::get('home/hero-banners', [HomeController::class, 'heroBanners'])
        ->name('api.v1.home.hero-banners');

    Route::get('home/service-categories', [HomeController::class, 'serviceCategories'])
        ->name('api.v1.home.service-categories');

    Route::get('home/featured-services', [HomeController::class, 'featuredServices'])
        ->name('api.v1.home.featured-services');

    Route::get('home/store-products', [HomeController::class, 'storeProducts'])
        ->name('api.v1.home.store-products');

    Route::get('home/before-after', [HomeController::class, 'beforeAfter'])
        ->name('api.v1.home.before-after');

    Route::get('home/reviews', [HomeController::class, 'reviews'])
        ->name('api.v1.home.reviews');

    Route::get('home/faq', [HomeController::class, 'faq'])
        ->name('api.v1.home.faq');

    Route::get('home/contact', [HomeController::class, 'contact'])
        ->name('api.v1.home.contact');

    Route::get('search', [SearchController::class, 'index'])
        ->name('api.v1.search');

    Route::get('search/suggestions', [SearchController::class, 'suggestions'])
        ->name('api.v1.search.suggestions');

    Route::get('products/search', ProductSearchController::class)
        ->name('api.v1.products.search');

    Route::get('service-categories', [CatalogServiceCategoryController::class, 'index'])
        ->name('api.v1.catalog.service-categories');

    Route::get('services/search', [CatalogServiceController::class, 'search'])
        ->name('api.v1.catalog.services.search');

    Route::get('services', [CatalogServiceController::class, 'index'])
        ->name('api.v1.catalog.services.index');

    Route::get('services/{slug}/reviews', [CatalogServiceReviewController::class, 'index'])
        ->name('api.v1.catalog.services.reviews');

    Route::get('services/{slug}', [CatalogServiceController::class, 'show'])
        ->name('api.v1.catalog.services.show');
});

Route::prefix('v1/reviews')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::post('/', [ReviewController::class, 'store'])
            ->middleware('throttle:reviews')
            ->name('api.v1.reviews.store');
        Route::get('/', [ReviewController::class, 'index'])
            ->name('api.v1.reviews.index');
        Route::put('{review}', [ReviewController::class, 'update'])
            ->whereNumber('review')
            ->name('api.v1.reviews.update');
        Route::delete('{review}', [ReviewController::class, 'destroy'])
            ->whereNumber('review')
            ->name('api.v1.reviews.destroy');
    });

Route::prefix('v1/favorites')
    ->middleware(['auth:sanctum', 'throttle:favorites'])
    ->group(function (): void {
        Route::post('/', [FavoriteController::class, 'store'])
            ->name('api.v1.favorites.store');
        Route::get('/', [FavoriteController::class, 'index'])
            ->name('api.v1.favorites.index');
        Route::delete('{service}', [FavoriteController::class, 'destroy'])
            ->whereNumber('service')
            ->name('api.v1.favorites.destroy');
    });

Route::prefix('v1/admin/reviews')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [AdminReviewController::class, 'index'])
            ->middleware('permission:reviews.view')
            ->name('api.v1.admin.reviews.index');

        Route::get('{review}', [AdminReviewController::class, 'show'])
            ->whereNumber('review')
            ->middleware('permission:reviews.view')
            ->name('api.v1.admin.reviews.show');

        Route::patch('{review}/approve', [AdminReviewController::class, 'approve'])
            ->whereNumber('review')
            ->middleware('permission:reviews.moderate')
            ->name('api.v1.admin.reviews.approve');

        Route::patch('{review}/hide', [AdminReviewController::class, 'hide'])
            ->whereNumber('review')
            ->middleware('permission:reviews.moderate')
            ->name('api.v1.admin.reviews.hide');
    });

Route::prefix('v1/admin')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('hero-banners', [AdminHeroBannerController::class, 'index'])
            ->middleware('permission:content.view')
            ->name('api.v1.admin.hero-banners.index');

        Route::post('hero-banners', [AdminHeroBannerController::class, 'store'])
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.hero-banners.store');

        Route::patch('hero-banners/{banner}', [AdminHeroBannerController::class, 'update'])
            ->whereNumber('banner')
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.hero-banners.update');

        Route::delete('hero-banners/{banner}', [AdminHeroBannerController::class, 'destroy'])
            ->whereNumber('banner')
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.hero-banners.destroy');

        Route::get('before-after', [AdminBeforeAfterItemController::class, 'index'])
            ->middleware('permission:content.view')
            ->name('api.v1.admin.before-after.index');

        Route::post('before-after', [AdminBeforeAfterItemController::class, 'store'])
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.before-after.store');

        Route::patch('before-after/{item}', [AdminBeforeAfterItemController::class, 'update'])
            ->whereNumber('item')
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.before-after.update');

        Route::delete('before-after/{item}', [AdminBeforeAfterItemController::class, 'destroy'])
            ->whereNumber('item')
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.before-after.destroy');

        Route::get('faqs', [AdminFaqController::class, 'index'])
            ->middleware('permission:content.view')
            ->name('api.v1.admin.faqs.index');

        Route::post('faqs', [AdminFaqController::class, 'store'])
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.faqs.store');

        Route::patch('faqs/{faq}', [AdminFaqController::class, 'update'])
            ->whereNumber('faq')
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.faqs.update');

        Route::delete('faqs/{faq}', [AdminFaqController::class, 'destroy'])
            ->whereNumber('faq')
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.faqs.destroy');

        Route::patch('services/{service}/featured', [AdminServiceFeaturedController::class, 'update'])
            ->whereNumber('service')
            ->middleware(['permission:content.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.services.featured');
    });

Route::prefix('v1/admin/payments')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [AdminPaymentController::class, 'index'])
            ->middleware('permission:payments.view')
            ->name('api.v1.admin.payments.index');

        Route::get('{payment}', [AdminPaymentController::class, 'show'])
            ->whereNumber('payment')
            ->middleware('permission:payments.view')
            ->name('api.v1.admin.payments.show');

        Route::patch('{payment}/confirm', [AdminPaymentController::class, 'confirm'])
            ->whereNumber('payment')
            ->middleware(['permission:payments.confirm', 'throttle:admin-operations'])
            ->name('api.v1.admin.payments.confirm');

        Route::patch('{payment}/reject', [AdminPaymentController::class, 'reject'])
            ->whereNumber('payment')
            ->middleware(['permission:payments.confirm', 'throttle:admin-operations'])
            ->name('api.v1.admin.payments.reject');
    });

Route::prefix('v1/admin/bookings')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [AdminBookingController::class, 'index'])
            ->middleware('permission:bookings.view')
            ->name('api.v1.admin.bookings.index');

        Route::get('{booking}', [AdminBookingController::class, 'show'])
            ->whereNumber('booking')
            ->middleware('permission:bookings.view')
            ->name('api.v1.admin.bookings.show');

        Route::patch('{booking}/schedule', [AdminBookingController::class, 'schedule'])
            ->whereNumber('booking')
            ->middleware(['permission:bookings.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.bookings.schedule');

        Route::patch('{booking}/start', [AdminBookingController::class, 'start'])
            ->whereNumber('booking')
            ->middleware(['permission:bookings.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.bookings.start');

        Route::patch('{booking}/complete', [AdminBookingController::class, 'complete'])
            ->whereNumber('booking')
            ->middleware(['permission:bookings.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.bookings.complete');

        Route::patch('{booking}/close', [AdminBookingController::class, 'close'])
            ->whereNumber('booking')
            ->middleware(['permission:bookings.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.bookings.close');

        Route::patch('{booking}/cancel', [AdminBookingController::class, 'cancel'])
            ->whereNumber('booking')
            ->middleware(['permission:bookings.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.bookings.cancel');
    });

Route::prefix('v1/admin/store-orders')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [AdminStoreOrderController::class, 'index'])
            ->middleware('permission:store_orders.view')
            ->name('api.v1.admin.store-orders.index');

        Route::get('{storeOrder}', [AdminStoreOrderController::class, 'show'])
            ->whereNumber('storeOrder')
            ->middleware('permission:store_orders.view')
            ->name('api.v1.admin.store-orders.show');

        Route::patch('{storeOrder}/status', [AdminStoreOrderController::class, 'updateStatus'])
            ->whereNumber('storeOrder')
            ->middleware(['permission:store_orders.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.store-orders.status');
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

    Route::get('permissions', [AdminAuthController::class, 'permissions'])
        ->middleware(['auth:sanctum', 'admin'])
        ->name('api.v1.admin.auth.permissions');

    Route::put('password', [AdminPasswordController::class, 'updateOwn'])
        ->middleware(['auth:sanctum', 'admin'])
        ->name('api.v1.admin.auth.password.update');
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

        Route::get('roles/{role}/permissions', [PermissionController::class, 'showRolePermissions'])
            ->middleware('permission:roles.manage')
            ->name('api.v1.admin.roles.permissions.show');

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

        Route::put('admins/{admin}/password', [AdminPasswordController::class, 'reset'])
            ->middleware('permission:admins.manage')
            ->name('api.v1.admin.admins.password.reset');

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

Route::prefix('v1/uploads')
    ->middleware('auth:sanctum')
    ->group(function (): void {
        Route::post('/', [UploadController::class, 'store'])
            ->middleware('throttle:uploads')
            ->name('api.v1.uploads.store');
        Route::get('/', [UploadController::class, 'index'])
            ->name('api.v1.uploads.index');
        Route::get('{uuid}', [UploadController::class, 'show'])
            ->whereUuid('uuid')
            ->name('api.v1.uploads.show');
        Route::delete('{uuid}', [UploadController::class, 'destroy'])
            ->whereUuid('uuid')
            ->name('api.v1.uploads.destroy');
    });

Route::post('v1/devices', [DeviceController::class, 'store'])
    ->middleware('auth:sanctum')
    ->name('api.v1.devices.store');

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
        Route::patch('{quotation}', [QuotationController::class, 'update'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.update');
        Route::post('{quotation}/submit', [QuotationController::class, 'submit'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.submit');
        Route::post('{quotation}/cancel', [QuotationController::class, 'cancel'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.cancel');
        Route::post('{quotation}/attachments', [QuotationAttachmentController::class, 'store'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.attachments.store');
        Route::get('{quotation}/attachments/{attachment}', [QuotationAttachmentController::class, 'show'])
            ->whereNumber('quotation')
            ->whereNumber('attachment')
            ->name('api.v1.quotations.attachments.show');
        Route::delete('{quotation}/attachments/{attachment}', [QuotationAttachmentController::class, 'destroy'])
            ->whereNumber('quotation')
            ->whereNumber('attachment')
            ->name('api.v1.quotations.attachments.destroy');
        Route::get('{quotation}/revisions', [QuotationRevisionController::class, 'index'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.revisions.index');
        Route::get('{quotation}/timeline', [QuotationTimelineController::class, 'index'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.timeline.index');
        Route::get('{quotation}/discussion', [QuotationDiscussionController::class, 'index'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.discussion.index');
        Route::post('{quotation}/discussion', [QuotationDiscussionController::class, 'store'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.discussion.store');
        Route::post('{quotation}/accept', [QuotationAcceptanceController::class, 'store'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.accept');
        Route::get('{quotation}', [QuotationController::class, 'show'])
            ->whereNumber('quotation')
            ->name('api.v1.quotations.show');
    });

Route::prefix('v1/admin/quotations')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('', [AdminQuotationController::class, 'index'])
            ->middleware('permission:quotations.view')
            ->name('api.v1.admin.quotations.index');

        Route::get('{quotation}', [AdminQuotationController::class, 'show'])
            ->whereNumber('quotation')
            ->middleware('permission:quotations.view')
            ->name('api.v1.admin.quotations.show');

        Route::get('{quotation}/attachments/{attachment}/download', [AdminQuotationController::class, 'downloadAttachment'])
            ->whereNumber('quotation')
            ->whereNumber('attachment')
            ->middleware('permission:quotations.view')
            ->name('api.v1.admin.quotations.attachments.download');

        Route::patch('{quotation}/assign', [AdminQuotationController::class, 'assign'])
            ->whereNumber('quotation')
            ->middleware(['permission:quotations.review', 'throttle:admin-operations'])
            ->name('api.v1.admin.quotations.assign');

        Route::post('{quotation}/issue', [AdminQuotationController::class, 'issue'])
            ->whereNumber('quotation')
            ->middleware(['permission:quotations.issue', 'throttle:admin-operations'])
            ->name('api.v1.admin.quotations.issue');

        Route::post('{quotation}/revisions', [AdminQuotationController::class, 'storeRevision'])
            ->whereNumber('quotation')
            ->middleware(['permission:quotations.issue', 'throttle:admin-operations'])
            ->name('api.v1.admin.quotations.revisions.store');

        Route::post('{quotation}/discussion', [AdminQuotationController::class, 'storeDiscussionMessage'])
            ->whereNumber('quotation')
            ->middleware(['permission:quotations.review', 'throttle:admin-operations'])
            ->name('api.v1.admin.quotations.discussion.store');

        Route::patch('{quotation}/close-discussion', [AdminQuotationController::class, 'closeDiscussion'])
            ->whereNumber('quotation')
            ->middleware(['permission:quotations.review', 'throttle:admin-operations'])
            ->name('api.v1.admin.quotations.close-discussion');

        Route::patch('{quotation}/expire', [AdminQuotationController::class, 'expire'])
            ->whereNumber('quotation')
            ->middleware(['permission:quotations.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.quotations.expire');

        Route::patch('{quotation}/cancel', [AdminQuotationController::class, 'cancel'])
            ->whereNumber('quotation')
            ->middleware(['permission:quotations.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.quotations.cancel');

        Route::patch('{quotation}/accept', [AdminQuotationController::class, 'accept'])
            ->whereNumber('quotation')
            ->middleware(['permission:quotations.manage', 'throttle:admin-operations'])
            ->name('api.v1.admin.quotations.accept');
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
    ->middleware('throttle:public-catalog')
    ->name('api.v1.products.index');
Route::get('v1/products/{product}', [ProductController::class, 'show'])
    ->middleware('throttle:public-catalog')
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

// The HR Dashboard has its own permission (hr.dashboard.view); every other HR read endpoint stays on hr.view.
Route::middleware(['auth:sanctum', 'admin', 'permission:hr.dashboard.view', 'throttle:admin-operations'])
    ->get('v1/admin/hr/dashboard', [AdminHrDashboardController::class, 'show'])
    ->name('api.v1.admin.hr.dashboard');

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/departments', [AdminDepartmentController::class, 'index'])
        ->name('api.v1.admin.hr.departments.index');
    Route::get('v1/admin/hr/positions', [AdminPositionController::class, 'index'])
        ->name('api.v1.admin.hr.positions.index');
    Route::get('v1/admin/hr/employee-categories', [AdminEmployeeCategoryController::class, 'index'])
        ->name('api.v1.admin.hr.employee-categories.index');
    Route::get('v1/admin/hr/client-companies', [AdminClientCompanyController::class, 'index'])
        ->name('api.v1.admin.hr.client-companies.index');
    Route::get('v1/admin/hr/work-locations', [AdminWorkLocationController::class, 'index'])
        ->name('api.v1.admin.hr.work-locations.index');
    Route::get('v1/admin/hr/client-companies/{clientCompany}/agreements', [AdminClientCompanyAgreementController::class, 'index'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.client-companies.agreements.index');
    Route::get('v1/admin/hr/client-companies/{clientCompany}/agreements/{agreement}/download', [AdminClientCompanyAgreementController::class, 'download'])
        ->whereNumber(['clientCompany', 'agreement'])
        ->name('api.v1.admin.hr.client-companies.agreements.download');
    Route::get('v1/admin/hr/employees', [AdminEmployeeController::class, 'index'])
        ->name('api.v1.admin.hr.employees.index');
    Route::get('v1/admin/hr/employees/duplicate-check', [AdminEmployeeController::class, 'duplicateCheck'])
        ->name('api.v1.admin.hr.employees.duplicate-check');
    Route::get('v1/admin/hr/employees/{employee}', [AdminEmployeeController::class, 'show'])
        ->whereNumber('employee')
        ->withTrashed()
        ->name('api.v1.admin.hr.employees.show');
    Route::get('v1/admin/hr/employees/{employee}/documents/{document}/download', [AdminEmployeeDocumentController::class, 'download'])
        ->whereNumber(['employee', 'document'])
        ->name('api.v1.admin.hr.employees.documents.download');
    Route::get('v1/admin/hr/employees/{employee}/guarantor', [AdminEmployeeGuarantorController::class, 'show'])
        ->whereNumber('employee')
        ->withTrashed()
        ->name('api.v1.admin.hr.employees.guarantor.show');
    Route::get('v1/admin/hr/employees/{employee}/contracts', [AdminEmployeeContractController::class, 'index'])
        ->whereNumber('employee')
        ->withTrashed()
        ->name('api.v1.admin.hr.employees.contracts.index');
    Route::get('v1/admin/hr/employees/{employee}/uniform', [AdminEmployeeUniformController::class, 'show'])
        ->whereNumber('employee')
        ->withTrashed()
        ->name('api.v1.admin.hr.employees.uniform.show');
    Route::get('v1/admin/hr/employee-document-categories', [AdminEmployeeDocumentCategoryController::class, 'index'])
        ->name('api.v1.admin.hr.employee-document-categories.index');
    Route::get('v1/admin/hr/training-batches', [AdminTrainingBatchController::class, 'index'])
        ->name('api.v1.admin.hr.training-batches.index');
    Route::get('v1/admin/hr/practical-batches', [AdminPracticalBatchController::class, 'index'])
        ->name('api.v1.admin.hr.practical-batches.index');
    Route::get('v1/admin/hr/workforce-requests', [AdminWorkforceRequestController::class, 'index'])
        ->name('api.v1.admin.hr.workforce-requests.index');
    Route::get('v1/admin/hr/workforce-requests/{workforceRequest}/candidates', [AdminWorkforceRequestController::class, 'candidates'])
        ->whereNumber('workforceRequest')
        ->name('api.v1.admin.hr.workforce-requests.candidates');
    Route::get('v1/admin/hr/temporary-replacements', [AdminTemporaryReplacementController::class, 'index'])
        ->name('api.v1.admin.hr.temporary-replacements.index');
    Route::get('v1/admin/hr/temporary-replacements/{temporaryReplacement}/workdays', [AdminTemporaryReplacementWorkdayController::class, 'index'])
        ->whereNumber('temporaryReplacement')
        ->name('api.v1.admin.hr.temporary-replacements.workdays.index');
    Route::get('v1/admin/hr/attendance', [AdminEmployeeAttendanceController::class, 'forDate'])
        ->name('api.v1.admin.hr.attendance.for-date');
    Route::get('v1/admin/hr/employees/{employee}/payroll-summary', [AdminEmployeePayrollController::class, 'summary'])
        ->whereNumber('employee')
        ->withTrashed()
        ->name('api.v1.admin.hr.employees.payroll-summary');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.reports.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/reports/summary', [AdminHrReportsController::class, 'summary'])
        ->name('api.v1.admin.hr.reports.summary');
    Route::get('v1/admin/hr/reports/waiting-roster', [AdminHrReportsController::class, 'waitingRoster'])
        ->name('api.v1.admin.hr.reports.waiting-roster');
    Route::get('v1/admin/hr/reports/workforce-requests', [AdminHrReportsController::class, 'workforceRequestHistory'])
        ->name('api.v1.admin.hr.reports.workforce-requests');
    Route::get('v1/admin/hr/reports/leaves', [AdminHrReportsController::class, 'leaves'])
        ->name('api.v1.admin.hr.reports.leaves');
    Route::get('v1/admin/hr/reports/performance-reviews', [AdminHrReportsController::class, 'performanceReviews'])
        ->name('api.v1.admin.hr.reports.performance-reviews');
});

// Approved salary boundary (owner decision: hr_view). These reports return pay amounts, so reaching them needs the permission
// that gates salary everywhere else (hr.view) in addition to the reports permission. hr.reports.view alone never reveals pay.
Route::middleware(['auth:sanctum', 'admin', 'permission:hr.reports.view', 'permission:hr.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/reports/temporary-replacements', [AdminHrReportsController::class, 'temporaryReplacementHistory'])
        ->name('api.v1.admin.hr.reports.temporary-replacements');
    Route::get('v1/admin/hr/reports/financial-ledger', [AdminHrReportsController::class, 'financialLedger'])
        ->name('api.v1.admin.hr.reports.financial-ledger');
    Route::get('v1/admin/hr/reports/payroll-rollup', [AdminHrReportsController::class, 'payrollRollup'])
        ->name('api.v1.admin.hr.reports.payroll-rollup');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.manage', 'throttle:admin-operations'])->group(function (): void {
    // Office Staff workflow: shows and writes emergency-contact, guarantor and salary details, so hr.manage only.
    Route::get('v1/admin/hr/office-staff/lookup', [AdminOfficeStaffController::class, 'lookup'])
        ->name('api.v1.admin.hr.office-staff.lookup');
    Route::get('v1/admin/hr/office-staff/{employee}', [AdminOfficeStaffController::class, 'show'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.office-staff.show');
    Route::post('v1/admin/hr/office-staff/assign', [AdminOfficeStaffController::class, 'assign'])
        ->name('api.v1.admin.hr.office-staff.assign');
    Route::post('v1/admin/hr/office-staff', [AdminOfficeStaffController::class, 'register'])
        ->name('api.v1.admin.hr.office-staff.register');
    Route::post('v1/admin/hr/departments', [AdminDepartmentController::class, 'store'])
        ->name('api.v1.admin.hr.departments.store');
    Route::put('v1/admin/hr/departments/{department}', [AdminDepartmentController::class, 'update'])
        ->whereNumber('department')
        ->name('api.v1.admin.hr.departments.update');
    Route::delete('v1/admin/hr/departments/{department}', [AdminDepartmentController::class, 'destroy'])
        ->whereNumber('department')
        ->name('api.v1.admin.hr.departments.destroy');

    Route::post('v1/admin/hr/employee-categories', [AdminEmployeeCategoryController::class, 'store'])
        ->name('api.v1.admin.hr.employee-categories.store');
    Route::put('v1/admin/hr/employee-categories/{employeeCategory}', [AdminEmployeeCategoryController::class, 'update'])
        ->whereNumber('employeeCategory')
        ->name('api.v1.admin.hr.employee-categories.update');
    Route::post('v1/admin/hr/employee-categories/{employeeCategory}/archive', [AdminEmployeeCategoryController::class, 'archive'])
        ->whereNumber('employeeCategory')
        ->name('api.v1.admin.hr.employee-categories.archive');
    Route::post('v1/admin/hr/employee-categories/{employeeCategory}/restore', [AdminEmployeeCategoryController::class, 'restore'])
        ->whereNumber('employeeCategory')
        ->name('api.v1.admin.hr.employee-categories.restore');

    Route::post('v1/admin/hr/positions', [AdminPositionController::class, 'store'])
        ->name('api.v1.admin.hr.positions.store');
    Route::put('v1/admin/hr/positions/{position}', [AdminPositionController::class, 'update'])
        ->whereNumber('position')
        ->name('api.v1.admin.hr.positions.update');
    Route::delete('v1/admin/hr/positions/{position}', [AdminPositionController::class, 'destroy'])
        ->whereNumber('position')
        ->name('api.v1.admin.hr.positions.destroy');

    Route::post('v1/admin/hr/employees', [AdminEmployeeController::class, 'store'])
        ->name('api.v1.admin.hr.employees.store');
    Route::put('v1/admin/hr/employees/{employee}', [AdminEmployeeController::class, 'update'])
        ->whereNumber('employee')
        ->withTrashed()
        ->name('api.v1.admin.hr.employees.update');
    Route::post('v1/admin/hr/employees/{employee}/restore', [AdminEmployeeController::class, 'restore'])
        ->whereNumber('employee')
        ->withTrashed()
        ->name('api.v1.admin.hr.employees.restore');
    Route::patch('v1/admin/hr/employees/{employee}/status', [AdminEmployeeStatusController::class, 'update'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.status');
    Route::post('v1/admin/hr/employees/{employee}/move-stage', [AdminEmployeeStatusController::class, 'moveStage'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.move-stage');
    Route::post('v1/admin/hr/employees/bulk-activate', [AdminEmployeeStatusController::class, 'bulkActivate'])
        ->name('api.v1.admin.hr.employees.bulk-activate');
    Route::patch('v1/admin/hr/employees/{employee}/separate', [AdminEmployeeSeparationController::class, 'separate'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.separate');
    Route::patch('v1/admin/hr/employees/{employee}/rehire', [AdminEmployeeSeparationController::class, 'rehire'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.rehire');
    Route::post('v1/admin/hr/employees/{employee}/attendance', [AdminEmployeeAttendanceController::class, 'mark'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.attendance.mark');
    Route::post('v1/admin/hr/employees/{employee}/leaves', [AdminEmployeeRecordsController::class, 'storeLeave'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.leaves.store');
    Route::patch('v1/admin/hr/employees/{employee}/leaves/{leave}/approve', [AdminEmployeeRecordsController::class, 'approveLeave'])
        ->whereNumber(['employee', 'leave'])
        ->name('api.v1.admin.hr.employees.leaves.approve');
    Route::patch('v1/admin/hr/employees/{employee}/leaves/{leave}/reject', [AdminEmployeeRecordsController::class, 'rejectLeave'])
        ->whereNumber(['employee', 'leave'])
        ->name('api.v1.admin.hr.employees.leaves.reject');
    Route::post('v1/admin/hr/employees/{employee}/performance-reviews', [AdminEmployeeRecordsController::class, 'storePerformanceReview'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.performance-reviews.store');
    Route::post('v1/admin/hr/employees/{employee}/payments', [AdminEmployeeRecordsController::class, 'storePayment'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.payments.store');
    Route::post('v1/admin/hr/employees/{employee}/penalties', [AdminEmployeeRecordsController::class, 'storePenalty'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.penalties.store');
    Route::post('v1/admin/hr/employees/{employee}/advances', [AdminEmployeeRecordsController::class, 'storeAdvance'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.advances.store');
    Route::patch('v1/admin/hr/employees/{employee}/supervisor', [AdminEmployeeSeparationController::class, 'supervisor'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.supervisor');
    Route::patch('v1/admin/hr/employees/{employee}/guarantor-confirm', [AdminEmployeeStatusController::class, 'confirmGuarantor'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.guarantor-confirm');
    Route::post('v1/admin/hr/employees/{employee}/practical-assessments', [AdminEmployeePracticalAssessmentController::class, 'store'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.practical-assessments.store');
    Route::post('v1/admin/hr/employees/{employee}/documents', [AdminEmployeeDocumentController::class, 'store'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.documents.store');
    Route::delete('v1/admin/hr/employees/{employee}/documents/{document}', [AdminEmployeeDocumentController::class, 'destroy'])
        ->whereNumber(['employee', 'document'])
        ->name('api.v1.admin.hr.employees.documents.destroy');

    Route::post('v1/admin/hr/employees/{employee}/guarantor', [AdminEmployeeGuarantorController::class, 'store'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.guarantor.store');
    Route::patch('v1/admin/hr/employees/{employee}/guarantor/verify', [AdminEmployeeGuarantorController::class, 'verify'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.guarantor.verify');

    Route::post('v1/admin/hr/employees/{employee}/contracts', [AdminEmployeeContractController::class, 'store'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.contracts.store');
    Route::patch('v1/admin/hr/employees/{employee}/contracts/{contract}/sign', [AdminEmployeeContractController::class, 'sign'])
        ->whereNumber(['employee', 'contract'])
        ->name('api.v1.admin.hr.employees.contracts.sign');

    Route::put('v1/admin/hr/employees/{employee}/uniform', [AdminEmployeeUniformController::class, 'update'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.uniform.update');
    Route::patch('v1/admin/hr/employees/{employee}/uniform/confirm', [AdminEmployeeUniformController::class, 'confirm'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.uniform.confirm');

    Route::post('v1/admin/hr/employee-document-categories', [AdminEmployeeDocumentCategoryController::class, 'store'])
        ->name('api.v1.admin.hr.employee-document-categories.store');
    Route::patch('v1/admin/hr/employees/{employee}/documents/{document}/verify', [AdminEmployeeDocumentController::class, 'verify'])
        ->whereNumber(['employee', 'document'])
        ->name('api.v1.admin.hr.employees.documents.verify');
    Route::patch('v1/admin/hr/employees/{employee}/documents/{document}/category', [AdminEmployeeDocumentController::class, 'updateCategory'])
        ->whereNumber(['employee', 'document'])
        ->name('api.v1.admin.hr.employees.documents.category');
    Route::post('v1/admin/hr/employees/{employee}/profile-picture', [AdminEmployeeProfilePictureController::class, 'store'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.profile-picture.store');

    Route::post('v1/admin/hr/training-batches', [AdminTrainingBatchController::class, 'store'])
        ->name('api.v1.admin.hr.training-batches.store');
    Route::put('v1/admin/hr/training-batches/{trainingBatch}', [AdminTrainingBatchController::class, 'update'])
        ->whereNumber('trainingBatch')
        ->name('api.v1.admin.hr.training-batches.update');
    Route::post('v1/admin/hr/training-batches/{trainingBatch}/participants', [AdminTrainingBatchController::class, 'addParticipant'])
        ->whereNumber('trainingBatch')
        ->name('api.v1.admin.hr.training-batches.participants.store');
    Route::delete('v1/admin/hr/training-batches/{trainingBatch}/participants/{participant}', [AdminTrainingBatchController::class, 'removeParticipant'])
        ->whereNumber(['trainingBatch', 'participant'])
        ->name('api.v1.admin.hr.training-batches.participants.destroy');
    Route::patch('v1/admin/hr/training-batches/{trainingBatch}/complete', [AdminTrainingBatchController::class, 'complete'])
        ->whereNumber('trainingBatch')
        ->name('api.v1.admin.hr.training-batches.complete');

    Route::post('v1/admin/hr/practical-batches', [AdminPracticalBatchController::class, 'store'])
        ->name('api.v1.admin.hr.practical-batches.store');
    Route::put('v1/admin/hr/practical-batches/{practicalBatch}', [AdminPracticalBatchController::class, 'update'])
        ->whereNumber('practicalBatch')
        ->name('api.v1.admin.hr.practical-batches.update');

    Route::post('v1/admin/hr/client-companies', [AdminClientCompanyController::class, 'store'])
        ->name('api.v1.admin.hr.client-companies.store');
    Route::put('v1/admin/hr/client-companies/{clientCompany}', [AdminClientCompanyController::class, 'update'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.client-companies.update');
    Route::delete('v1/admin/hr/client-companies/{clientCompany}', [AdminClientCompanyController::class, 'destroy'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.client-companies.destroy');
    Route::post('v1/admin/hr/client-companies/{clientCompany}/agreements', [AdminClientCompanyAgreementController::class, 'store'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.client-companies.agreements.store');

    Route::post('v1/admin/hr/work-locations', [AdminWorkLocationController::class, 'store'])
        ->name('api.v1.admin.hr.work-locations.store');
    Route::put('v1/admin/hr/work-locations/{workLocation}', [AdminWorkLocationController::class, 'update'])
        ->whereNumber('workLocation')
        ->name('api.v1.admin.hr.work-locations.update');
    Route::delete('v1/admin/hr/work-locations/{workLocation}', [AdminWorkLocationController::class, 'destroy'])
        ->whereNumber('workLocation')
        ->name('api.v1.admin.hr.work-locations.destroy');

    Route::post('v1/admin/hr/employees/{employee}/work-assignments', [AdminEmployeeWorkAssignmentController::class, 'store'])
        ->whereNumber('employee')
        ->name('api.v1.admin.hr.employees.work-assignments.store');
    Route::put('v1/admin/hr/work-assignments/{workAssignment}', [AdminEmployeeWorkAssignmentController::class, 'update'])
        ->whereNumber('workAssignment')
        ->name('api.v1.admin.hr.work-assignments.update');
    Route::patch('v1/admin/hr/work-assignments/{workAssignment}/end', [AdminEmployeeWorkAssignmentController::class, 'end'])
        ->whereNumber('workAssignment')
        ->name('api.v1.admin.hr.work-assignments.end');
    Route::patch('v1/admin/hr/work-assignments/{workAssignment}/cancel', [AdminEmployeeWorkAssignmentController::class, 'cancel'])
        ->whereNumber('workAssignment')
        ->name('api.v1.admin.hr.work-assignments.cancel');
    Route::get('v1/admin/hr/work-assignments/{workAssignment}/successor-options', AssignmentSuccessorOptionsController::class)
        ->whereNumber('workAssignment')
        ->name('api.v1.admin.hr.work-assignments.successor-options');

    Route::post('v1/admin/hr/workforce-requests', [AdminWorkforceRequestController::class, 'store'])
        ->name('api.v1.admin.hr.workforce-requests.store');
    Route::put('v1/admin/hr/workforce-requests/{workforceRequest}', [AdminWorkforceRequestController::class, 'update'])
        ->whereNumber('workforceRequest')
        ->name('api.v1.admin.hr.workforce-requests.update');
    Route::patch('v1/admin/hr/workforce-requests/{workforceRequest}/cancel', [AdminWorkforceRequestController::class, 'cancel'])
        ->whereNumber('workforceRequest')
        ->name('api.v1.admin.hr.workforce-requests.cancel');
    Route::post('v1/admin/hr/workforce-requests/{workforceRequest}/confirm', [AdminWorkforceRequestController::class, 'confirm'])
        ->whereNumber('workforceRequest')
        ->name('api.v1.admin.hr.workforce-requests.confirm');

    Route::post('v1/admin/hr/temporary-replacements', [AdminTemporaryReplacementController::class, 'store'])
        ->name('api.v1.admin.hr.temporary-replacements.store');
    // Read-only preview of the same overlap rules the save enforces (docs/HR_PAYROLL_RULES.md U1, U12): lets the form warn before anything is saved.
    Route::get('v1/admin/hr/temporary-replacements/conflict-check', [AdminTemporaryReplacementController::class, 'conflictCheck'])
        ->name('api.v1.admin.hr.temporary-replacements.conflict-check');
    Route::patch('v1/admin/hr/temporary-replacements/{temporaryReplacement}/end', [AdminTemporaryReplacementController::class, 'end'])
        ->whereNumber('temporaryReplacement')
        ->name('api.v1.admin.hr.temporary-replacements.end');
    Route::post('v1/admin/hr/temporary-replacements/{temporaryReplacement}/payments', [AdminTemporaryReplacementController::class, 'addPayment'])
        ->whereNumber('temporaryReplacement')
        ->name('api.v1.admin.hr.temporary-replacements.payments.store');
    Route::post('v1/admin/hr/temporary-replacements/{temporaryReplacement}/workdays', [AdminTemporaryReplacementWorkdayController::class, 'store'])
        ->whereNumber('temporaryReplacement')
        ->name('api.v1.admin.hr.temporary-replacements.workdays.store');
    Route::patch('v1/admin/hr/temporary-replacements/{temporaryReplacement}/workdays/{workday}/verify', [AdminTemporaryReplacementWorkdayController::class, 'verify'])
        ->whereNumber(['temporaryReplacement', 'workday'])
        ->name('api.v1.admin.hr.temporary-replacements.workdays.verify');
    Route::patch('v1/admin/hr/temporary-replacements/{temporaryReplacement}/workdays/{workday}/void', [AdminTemporaryReplacementWorkdayController::class, 'void'])
        ->whereNumber(['temporaryReplacement', 'workday'])
        ->name('api.v1.admin.hr.temporary-replacements.workdays.void');
});

// Company Employee Management (the employees of one company). Reading: hr.view. Changing: hr.manage AND hr.view, so the
// screen's own salary figures never reach a caller who may not read salaries.
Route::middleware(['auth:sanctum', 'admin', 'permission:hr.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/client-companies/{clientCompany}/employees', [AdminCompanyEmployeeController::class, 'index'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.company-employees.index');
    Route::get('v1/admin/hr/client-companies/{clientCompany}/employee-candidates', [AdminCompanyEmployeeController::class, 'candidates'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.company-employees.candidates');
    Route::get('v1/admin/hr/client-companies/{clientCompany}/employee-history', [AdminCompanyEmployeeController::class, 'history'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.company-employees.history');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.manage', 'permission:hr.view', 'throttle:admin-operations'])->group(function (): void {
    Route::post('v1/admin/hr/client-companies/{clientCompany}/employees', [AdminCompanyEmployeeController::class, 'store'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.company-employees.store');
    Route::put('v1/admin/hr/client-companies/{clientCompany}/employees/{workAssignment}', [AdminCompanyEmployeeController::class, 'update'])
        ->whereNumber(['clientCompany', 'workAssignment'])
        ->name('api.v1.admin.hr.company-employees.update');
    Route::post('v1/admin/hr/client-companies/{clientCompany}/employees/{workAssignment}/replace', [AdminCompanyEmployeeController::class, 'replace'])
        ->whereNumber(['clientCompany', 'workAssignment'])
        ->name('api.v1.admin.hr.company-employees.replace');
    Route::post('v1/admin/hr/client-companies/{clientCompany}/employees/{workAssignment}/return', [AdminCompanyEmployeeController::class, 'returnEmployee'])
        ->whereNumber(['clientCompany', 'workAssignment'])
        ->name('api.v1.admin.hr.company-employees.return');
    Route::post('v1/admin/hr/client-companies/{clientCompany}/employees/{workAssignment}/stop', [AdminCompanyEmployeeController::class, 'stop'])
        ->whereNumber(['clientCompany', 'workAssignment'])
        ->name('api.v1.admin.hr.company-employees.stop');
});

// HR policies: anyone who may view HR can see them; the roles each policy lists (Super Admin, and the HR Manager for the multi-company switch)
// can change it - the route needs hr.manage and the action re-checks the role and the policy's own list.
Route::middleware(['auth:sanctum', 'admin', 'permission:hr.view', 'throttle:admin-operations'])->get('v1/admin/hr/policies', [AdminHrPolicyController::class, 'index'])
    ->name('api.v1.admin.hr.policies.index');
Route::middleware(['auth:sanctum', 'admin', 'permission:hr.manage', 'throttle:admin-operations'])->put('v1/admin/hr/policies/{key}', [AdminHrPolicyController::class, 'update'])
    ->name('api.v1.admin.hr.policies.update');

// HR Excel imports: need the dedicated hr.import permission, which only the Super Admin (implicitly) and the HR Manager
// role can hold - it can never be granted to another role (see AdminPermission::restrictedToRoles()).
Route::middleware(['auth:sanctum', 'admin', 'permission:hr.import', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/imports', [AdminHrImportController::class, 'index'])
        ->name('api.v1.admin.hr.imports.index');
    Route::post('v1/admin/hr/imports', [AdminHrImportController::class, 'store'])
        ->name('api.v1.admin.hr.imports.store');
    Route::get('v1/admin/hr/imports/{importBatch}', [AdminHrImportController::class, 'show'])
        ->whereNumber('importBatch')
        ->name('api.v1.admin.hr.imports.show');
    Route::get('v1/admin/hr/imports/{importBatch}/rows', [AdminHrImportController::class, 'rows'])
        ->whereNumber('importBatch')
        ->name('api.v1.admin.hr.imports.rows');
    Route::get('v1/admin/hr/imports/{importBatch}/report', [AdminHrImportController::class, 'report'])
        ->whereNumber('importBatch')
        ->name('api.v1.admin.hr.imports.report');
    Route::post('v1/admin/hr/imports/{importBatch}/confirm', [AdminHrImportController::class, 'confirm'])
        ->whereNumber('importBatch')
        ->name('api.v1.admin.hr.imports.confirm');
    Route::post('v1/admin/hr/imports/{importBatch}/process', [AdminHrImportController::class, 'process'])
        ->whereNumber('importBatch')
        ->name('api.v1.admin.hr.imports.process');
    Route::post('v1/admin/hr/imports/{importBatch}/cancel', [AdminHrImportController::class, 'cancel'])
        ->whereNumber('importBatch')
        ->name('api.v1.admin.hr.imports.cancel');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.payroll.manage', 'throttle:admin-operations'])->group(function (): void {
    Route::post('v1/admin/hr/payroll-runs', [AdminPayrollRunController::class, 'store'])
        ->name('api.v1.admin.hr.payroll-runs.store');
    Route::patch('v1/admin/hr/payroll-runs/{payrollRun}/review', [AdminPayrollRunController::class, 'review'])
        ->whereNumber('payrollRun')
        ->name('api.v1.admin.hr.payroll-runs.review');
    // Money outside payroll for a correction (underpayment paid by a Super Admin; debt repaid - Super Admin / HR Manager). The role is checked again in the action.
    Route::post('v1/admin/hr/payroll-corrections/{payrollCorrection}/transactions', [AdminPayrollCorrectionController::class, 'store'])
        ->whereNumber('payrollCorrection')
        ->name('api.v1.admin.hr.payroll-corrections.transactions.store');
    Route::post('v1/admin/hr/payroll-corrections/{payrollCorrection}/transactions/{transaction}/void', [AdminPayrollCorrectionController::class, 'void'])
        ->whereNumber(['payrollCorrection', 'transaction'])
        ->name('api.v1.admin.hr.payroll-corrections.transactions.void');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.payroll.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/temporary-replacements/{temporaryReplacement}/cost', [AdminTemporaryReplacementWorkdayController::class, 'cost'])
        ->whereNumber('temporaryReplacement')
        ->name('api.v1.admin.hr.temporary-replacements.cost');
    Route::get('v1/admin/hr/temporary-replacements/{temporaryReplacement}/expected-payment', [AdminTemporaryReplacementWorkdayController::class, 'expectedPayment'])
        ->whereNumber('temporaryReplacement')
        ->name('api.v1.admin.hr.temporary-replacements.expected-payment');
    Route::get('v1/admin/hr/payroll-corrections', [AdminPayrollCorrectionController::class, 'index'])
        ->name('api.v1.admin.hr.payroll-corrections.index');
    Route::get('v1/admin/hr/payroll-runs', [AdminPayrollRunController::class, 'index'])
        ->name('api.v1.admin.hr.payroll-runs.index');
    Route::get('v1/admin/hr/payroll-runs/{payrollRun}', [AdminPayrollRunController::class, 'show'])
        ->whereNumber('payrollRun')
        ->name('api.v1.admin.hr.payroll-runs.show');
    Route::get('v1/admin/hr/payroll-runs/{payrollRun}/export', [AdminPayrollRunController::class, 'export'])
        ->whereNumber('payrollRun')
        ->name('api.v1.admin.hr.payroll-runs.export');
});

// Facility Inspections, complaints and their reports (Phase 2). Reading needs hr.inspections.view, writing hr.inspections.manage, the report
// also hr.reports.view. Records are voided with a reason, never deleted. Due/overdue states are calculated when asked: no scheduler is involved.
Route::middleware(['auth:sanctum', 'admin', 'permission:hr.inspections.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/facility-inspections/status', [AdminFacilityInspectionController::class, 'status'])
        ->name('api.v1.admin.hr.facility-inspections.status');
    Route::get('v1/admin/hr/facility-inspections/deep-cleaning', [AdminFacilityDeepCleaningController::class, 'index'])
        ->name('api.v1.admin.hr.facility-inspections.deep-cleaning');
    Route::get('v1/admin/hr/facility-inspections', [AdminFacilityInspectionController::class, 'index'])
        ->name('api.v1.admin.hr.facility-inspections.index');
    Route::get('v1/admin/hr/facility-inspections/{facilityInspection}', [AdminFacilityInspectionController::class, 'show'])
        ->whereNumber('facilityInspection')
        ->name('api.v1.admin.hr.facility-inspections.show');
    Route::get('v1/admin/hr/facility-complaints', [AdminFacilityComplaintController::class, 'index'])
        ->name('api.v1.admin.hr.facility-complaints.index');
    Route::get('v1/admin/hr/facility-complaints/{facilityComplaint}', [AdminFacilityComplaintController::class, 'show'])
        ->whereNumber('facilityComplaint')
        ->name('api.v1.admin.hr.facility-complaints.show');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.inspections.view', 'permission:hr.reports.view', 'throttle:admin-operations'])
    ->get('v1/admin/hr/reports/facility', [AdminFacilityReportController::class, 'show'])
    ->name('api.v1.admin.hr.reports.facility');

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.inspections.manage', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/facility-inspections/company-employees/{clientCompany}', [AdminFacilityInspectionController::class, 'companyEmployees'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.facility-inspections.company-employees');
    Route::post('v1/admin/hr/facility-inspections', [AdminFacilityInspectionController::class, 'store'])
        ->name('api.v1.admin.hr.facility-inspections.store');
    Route::put('v1/admin/hr/facility-inspections/{facilityInspection}', [AdminFacilityInspectionController::class, 'update'])
        ->whereNumber('facilityInspection')
        ->name('api.v1.admin.hr.facility-inspections.update');
    Route::post('v1/admin/hr/facility-inspections/{facilityInspection}/void', [AdminFacilityInspectionController::class, 'void'])
        ->whereNumber('facilityInspection')
        ->name('api.v1.admin.hr.facility-inspections.void');
    Route::put('v1/admin/hr/facility-inspection-schedules/{clientCompany}', [AdminFacilityInspectionController::class, 'setSchedule'])
        ->whereNumber('clientCompany')
        ->name('api.v1.admin.hr.facility-inspection-schedules.set');
    Route::post('v1/admin/hr/facility-complaints', [AdminFacilityComplaintController::class, 'store'])
        ->name('api.v1.admin.hr.facility-complaints.store');
    Route::put('v1/admin/hr/facility-complaints/{facilityComplaint}', [AdminFacilityComplaintController::class, 'update'])
        ->whereNumber('facilityComplaint')
        ->name('api.v1.admin.hr.facility-complaints.update');
    Route::post('v1/admin/hr/facility-complaints/{facilityComplaint}/status', [AdminFacilityComplaintController::class, 'transition'])
        ->whereNumber('facilityComplaint')
        ->name('api.v1.admin.hr.facility-complaints.status');
    Route::post('v1/admin/hr/facility-complaints/{facilityComplaint}/satisfaction', [AdminFacilityComplaintController::class, 'satisfaction'])
        ->whereNumber('facilityComplaint')
        ->name('api.v1.admin.hr.facility-complaints.satisfaction');
    Route::post('v1/admin/hr/facility-complaints/{facilityComplaint}/void', [AdminFacilityComplaintController::class, 'void'])
        ->whereNumber('facilityComplaint')
        ->name('api.v1.admin.hr.facility-complaints.void');
});

// The monthly payment list contains employees' payment/account numbers: it needs payroll access AND the
// permission that already gates those numbers everywhere else (hr.manage). Read-only; pays nothing.
Route::middleware(['auth:sanctum', 'admin', 'permission:hr.payroll.view', 'permission:hr.manage', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/hr/reports/monthly-payment-list', [AdminMonthlyPaymentReportController::class, 'download'])
        ->name('api.v1.admin.hr.reports.monthly-payment-list');
    // The Companies and Guryo that exist, for the download's filter (same permissions as the download itself).
    Route::get('v1/admin/hr/reports/monthly-payment-list/filters', [AdminMonthlyPaymentReportController::class, 'filters'])
        ->name('api.v1.admin.hr.reports.monthly-payment-list.filters');
    // Totals only (row count, Permanent / Temporary / combined, missing numbers) for the confirmation shown before the download; same permissions.
    Route::get('v1/admin/hr/reports/monthly-payment-list/preview', [AdminMonthlyPaymentReportController::class, 'preview'])
        ->name('api.v1.admin.hr.reports.monthly-payment-list.preview');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:hr.payroll.approve', 'throttle:admin-operations'])->group(function (): void {
    Route::patch('v1/admin/hr/temporary-replacements/{temporaryReplacement}/approve', [AdminTemporaryReplacementWorkdayController::class, 'approve'])
        ->whereNumber('temporaryReplacement')
        ->name('api.v1.admin.hr.temporary-replacements.approve');
    Route::patch('v1/admin/hr/payroll-runs/{payrollRun}/approve', [AdminPayrollRunController::class, 'approve'])
        ->whereNumber('payrollRun')
        ->name('api.v1.admin.hr.payroll-runs.approve');
    Route::patch('v1/admin/hr/payroll-runs/{payrollRun}/reopen', [AdminPayrollRunController::class, 'reopen'])
        ->whereNumber('payrollRun')
        ->name('api.v1.admin.hr.payroll-runs.reopen');
    Route::patch('v1/admin/hr/payroll-runs/{payrollRun}/finalize', [AdminPayrollRunController::class, 'finalize'])
        ->whereNumber('payrollRun')
        ->name('api.v1.admin.hr.payroll-runs.finalize');
    Route::post('v1/admin/hr/payroll-items/{payrollItem}/adjustments', [AdminPayrollRunController::class, 'adjust'])
        ->whereNumber('payrollItem')
        ->name('api.v1.admin.hr.payroll-items.adjustments.store');
    Route::post('v1/admin/hr/payroll-items/{payrollItem}/pay', [AdminPayrollRunController::class, 'pay'])
        ->whereNumber('payrollItem')
        ->name('api.v1.admin.hr.payroll-items.pay');
    // Voiding a payment marked paid: the role (Super Admin / HR Manager) is checked again in the action.
    Route::post('v1/admin/hr/payroll-items/{payrollItem}/void-payment', [AdminPayrollRunController::class, 'voidPayment'])
        ->whereNumber('payrollItem')
        ->name('api.v1.admin.hr.payroll-items.void-payment');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:reconciliation.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/reconciliation/data-issues', [DataIssueController::class, 'index'])
        ->name('api.v1.admin.reconciliation.data-issues.index');
    Route::get('v1/admin/reconciliation/data-issues/summary', [DataIssueController::class, 'summary'])
        ->name('api.v1.admin.reconciliation.data-issues.summary');
    Route::get('v1/admin/reconciliation/data-issues/{dataIssue}', [DataIssueController::class, 'show'])
        ->whereNumber('dataIssue')
        ->name('api.v1.admin.reconciliation.data-issues.show');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:reconciliation.create', 'throttle:admin-operations'])->group(function (): void {
    Route::post('v1/admin/reconciliation/data-issues', [DataIssueController::class, 'store'])
        ->name('api.v1.admin.reconciliation.data-issues.store');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:reconciliation.update', 'throttle:admin-operations'])->group(function (): void {
    Route::put('v1/admin/reconciliation/data-issues/{dataIssue}', [DataIssueController::class, 'update'])
        ->whereNumber('dataIssue')
        ->name('api.v1.admin.reconciliation.data-issues.update');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:reconciliation.resolve', 'throttle:admin-operations'])->group(function (): void {
    Route::patch('v1/admin/reconciliation/data-issues/{dataIssue}/resolve', [DataIssueController::class, 'resolve'])
        ->whereNumber('dataIssue')
        ->name('api.v1.admin.reconciliation.data-issues.resolve');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:marketing.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/marketing/dashboard', [AdminMarketingDashboardController::class, 'show'])
        ->name('api.v1.admin.marketing.dashboard');
    Route::get('v1/admin/marketing/teams', [AdminMarketingTeamController::class, 'index'])
        ->name('api.v1.admin.marketing.teams.index');
    Route::get('v1/admin/marketing/records', [AdminMarketingRecordController::class, 'index'])
        ->name('api.v1.admin.marketing.records.index');
    Route::get('v1/admin/marketing/records/{record}', [AdminMarketingRecordController::class, 'show'])
        ->whereNumber('record')
        ->name('api.v1.admin.marketing.records.show');
    Route::get('v1/admin/marketing/follow-ups', [AdminMarketingFollowUpController::class, 'index'])
        ->name('api.v1.admin.marketing.follow-ups.index');
    Route::get('v1/admin/marketing/employees', [AdminMarketingEmployeeController::class, 'index'])
        ->name('api.v1.admin.marketing.employees.index');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:marketing.commission.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/marketing/commission/rates', [AdminMarketingCommissionController::class, 'rates'])
        ->name('api.v1.admin.marketing.commission.rates.index');
    Route::get('v1/admin/marketing/commission/records', [AdminMarketingCommissionController::class, 'records'])
        ->name('api.v1.admin.marketing.commission.records.index');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:marketing.reports.view', 'throttle:admin-operations'])->group(function (): void {
    Route::get('v1/admin/marketing/reports/summary', [AdminMarketingReportsController::class, 'summary'])
        ->name('api.v1.admin.marketing.reports.summary');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:marketing.assign', 'throttle:admin-operations'])->group(function (): void {
    Route::patch('v1/admin/marketing/records/{record}/assign', [AdminMarketingRecordController::class, 'assign'])
        ->whereNumber('record')
        ->name('api.v1.admin.marketing.records.assign');
});

Route::middleware(['auth:sanctum', 'admin', 'permission:marketing.manage', 'throttle:admin-operations'])->group(function (): void {
    Route::post('v1/admin/marketing/teams', [AdminMarketingTeamController::class, 'store'])
        ->name('api.v1.admin.marketing.teams.store');
    Route::put('v1/admin/marketing/teams/{team}', [AdminMarketingTeamController::class, 'update'])
        ->whereNumber('team')
        ->name('api.v1.admin.marketing.teams.update');
    Route::delete('v1/admin/marketing/teams/{team}', [AdminMarketingTeamController::class, 'destroy'])
        ->whereNumber('team')
        ->name('api.v1.admin.marketing.teams.destroy');
    Route::post('v1/admin/marketing/employees', [AdminMarketingEmployeeController::class, 'store'])
        ->name('api.v1.admin.marketing.employees.store');
    Route::patch('v1/admin/marketing/employees/{admin}', [AdminMarketingEmployeeController::class, 'update'])
        ->whereNumber('admin')
        ->name('api.v1.admin.marketing.employees.update');
    Route::post('v1/admin/marketing/teams/{team}/members', [AdminMarketingTeamController::class, 'addMember'])
        ->whereNumber('team')
        ->name('api.v1.admin.marketing.teams.members.store');
    Route::delete('v1/admin/marketing/teams/{team}/members/{admin}', [AdminMarketingTeamController::class, 'removeMember'])
        ->whereNumber(['team', 'admin'])
        ->name('api.v1.admin.marketing.teams.members.destroy');

    Route::post('v1/admin/marketing/xarun', [AdminMarketingXarunController::class, 'store'])
        ->name('api.v1.admin.marketing.xarun.store');
    Route::put('v1/admin/marketing/xarun/{record}', [AdminMarketingXarunController::class, 'update'])
        ->whereNumber('record')
        ->name('api.v1.admin.marketing.xarun.update');

    Route::post('v1/admin/marketing/project', [AdminMarketingProjectController::class, 'store'])
        ->name('api.v1.admin.marketing.project.store');
    Route::put('v1/admin/marketing/project/{record}', [AdminMarketingProjectController::class, 'update'])
        ->whereNumber('record')
        ->name('api.v1.admin.marketing.project.update');

    Route::patch('v1/admin/marketing/records/{record}/status', [AdminMarketingRecordController::class, 'updateStatus'])
        ->whereNumber('record')
        ->name('api.v1.admin.marketing.records.status');

    Route::post('v1/admin/marketing/records/{record}/follow-ups', [AdminMarketingFollowUpController::class, 'store'])
        ->whereNumber('record')
        ->name('api.v1.admin.marketing.records.follow-ups.store');
    Route::patch('v1/admin/marketing/follow-ups/{followUp}/complete', [AdminMarketingFollowUpController::class, 'complete'])
        ->whereNumber('followUp')
        ->name('api.v1.admin.marketing.follow-ups.complete');
    Route::patch('v1/admin/marketing/follow-ups/{followUp}/reschedule', [AdminMarketingFollowUpController::class, 'reschedule'])
        ->whereNumber('followUp')
        ->name('api.v1.admin.marketing.follow-ups.reschedule');
    Route::patch('v1/admin/marketing/follow-ups/{followUp}/feedback', [AdminMarketingFollowUpController::class, 'addFeedback'])
        ->whereNumber('followUp')
        ->name('api.v1.admin.marketing.follow-ups.feedback');
    Route::patch('v1/admin/marketing/follow-ups/{followUp}/status', [AdminMarketingFollowUpController::class, 'updateRecordStatus'])
        ->whereNumber('followUp')
        ->name('api.v1.admin.marketing.follow-ups.status');

    Route::post('v1/admin/marketing/records/{record}/quotations', [AdminMarketingQuotationController::class, 'store'])
        ->whereNumber('record')
        ->name('api.v1.admin.marketing.records.quotations.store');
    Route::patch('v1/admin/marketing/quotations/{quotation}/status', [AdminMarketingQuotationController::class, 'updateStatus'])
        ->whereNumber('quotation')
        ->name('api.v1.admin.marketing.quotations.status');

    Route::post('v1/admin/marketing/commission/rates', [AdminMarketingCommissionController::class, 'storeRate'])
        ->name('api.v1.admin.marketing.commission.rates.store');
    Route::post('v1/admin/marketing/records/{record}/commission', [AdminMarketingCommissionController::class, 'storeRecord'])
        ->whereNumber('record')
        ->name('api.v1.admin.marketing.records.commission.store');
    Route::patch('v1/admin/marketing/commission/records/{commissionRecord}', [AdminMarketingCommissionController::class, 'updateRecord'])
        ->whereNumber('commissionRecord')
        ->name('api.v1.admin.marketing.commission.records.update');
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

// Somali / English wording edits made by Super Admin in the Web Panel. Everyone signed in reads the overrides (the panel needs them to render);
// only Super Admin changes them (role checked by the `super_admin` middleware, not by a permission).
Route::prefix('v1/admin/i18n')
    ->middleware(['auth:sanctum', 'admin'])
    ->group(function (): void {
        Route::get('overrides', [TranslationOverrideController::class, 'index'])
            ->name('api.v1.admin.i18n.overrides.index');

        Route::put('overrides/{key}', [TranslationOverrideController::class, 'update'])
            ->middleware('super_admin')
            ->name('api.v1.admin.i18n.overrides.update');

        Route::delete('overrides/{key}', [TranslationOverrideController::class, 'destroy'])
            ->middleware('super_admin')
            ->name('api.v1.admin.i18n.overrides.destroy');
    });
