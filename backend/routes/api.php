<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use App\Http\Controllers\Api\V1\Cart\CartController;
use App\Http\Controllers\Api\V1\Checkout\CheckoutController;
use App\Http\Controllers\Api\V1\Customer\CustomerAddressController;
use App\Http\Controllers\Api\V1\Customer\CustomerProfileController;
use App\Http\Controllers\Api\V1\GoodsReceipt\GoodsReceiptController;
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
    ->middleware('auth:sanctum')
    ->name('api.v1.products.store');
Route::put('v1/products/{product}', [ProductController::class, 'update'])
    ->middleware('auth:sanctum')
    ->whereNumber('product')
    ->name('api.v1.products.update');
Route::delete('v1/products/{product}', [ProductController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->whereNumber('product')
    ->name('api.v1.products.destroy');

Route::post('v1/products/{product}/images', [ProductImageController::class, 'store'])
    ->middleware('auth:sanctum')
    ->whereNumber('product')
    ->name('api.v1.products.images.store');
Route::patch('v1/products/{product}/images/reorder', [ProductImageController::class, 'reorder'])
    ->middleware('auth:sanctum')
    ->whereNumber('product')
    ->name('api.v1.products.images.reorder');
Route::patch('v1/products/{product}/images/{image}/primary', [ProductImageController::class, 'primary'])
    ->middleware('auth:sanctum')
    ->whereNumber('product')
    ->whereNumber('image')
    ->name('api.v1.products.images.primary');
Route::delete('v1/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->whereNumber('product')
    ->whereNumber('image')
    ->name('api.v1.products.images.destroy');

Route::get('v1/suppliers', [SupplierController::class, 'index'])
    ->middleware('auth:sanctum')
    ->name('api.v1.suppliers.index');
Route::get('v1/suppliers/{supplier}', [SupplierController::class, 'show'])
    ->middleware('auth:sanctum')
    ->whereNumber('supplier')
    ->name('api.v1.suppliers.show');
Route::post('v1/suppliers', [SupplierController::class, 'store'])
    ->middleware('auth:sanctum')
    ->name('api.v1.suppliers.store');
Route::put('v1/suppliers/{supplier}', [SupplierController::class, 'update'])
    ->middleware('auth:sanctum')
    ->whereNumber('supplier')
    ->name('api.v1.suppliers.update');
Route::delete('v1/suppliers/{supplier}', [SupplierController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->whereNumber('supplier')
    ->name('api.v1.suppliers.destroy');

Route::get('v1/purchase-orders', [PurchaseOrderController::class, 'index'])
    ->middleware('auth:sanctum')
    ->name('api.v1.purchase-orders.index');
Route::get('v1/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
    ->middleware('auth:sanctum')
    ->whereNumber('purchaseOrder')
    ->name('api.v1.purchase-orders.show');
Route::post('v1/purchase-orders', [PurchaseOrderController::class, 'store'])
    ->middleware('auth:sanctum')
    ->name('api.v1.purchase-orders.store');
Route::put('v1/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
    ->middleware('auth:sanctum')
    ->whereNumber('purchaseOrder')
    ->name('api.v1.purchase-orders.update');
Route::patch('v1/purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])
    ->middleware('auth:sanctum')
    ->whereNumber('purchaseOrder')
    ->name('api.v1.purchase-orders.submit');
Route::patch('v1/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
    ->middleware('auth:sanctum')
    ->whereNumber('purchaseOrder')
    ->name('api.v1.purchase-orders.approve');
Route::patch('v1/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
    ->middleware('auth:sanctum')
    ->whereNumber('purchaseOrder')
    ->name('api.v1.purchase-orders.cancel');

Route::get('v1/goods-receipts', [GoodsReceiptController::class, 'index'])
    ->middleware('auth:sanctum')
    ->name('api.v1.goods-receipts.index');
Route::get('v1/goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])
    ->middleware('auth:sanctum')
    ->whereNumber('goodsReceipt')
    ->name('api.v1.goods-receipts.show');
Route::post('v1/goods-receipts', [GoodsReceiptController::class, 'store'])
    ->middleware('auth:sanctum')
    ->name('api.v1.goods-receipts.store');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
