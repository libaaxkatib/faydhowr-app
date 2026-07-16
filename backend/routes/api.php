<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use App\Http\Controllers\Api\V1\Booking\BookingController;
use App\Http\Controllers\Api\V1\Customer\CustomerAddressController;
use App\Http\Controllers\Api\V1\Customer\CustomerProfileController;
use App\Http\Controllers\Api\V1\Order\OrderController;
use App\Http\Controllers\Api\V1\Order\OrderLifecycleController;
use App\Http\Controllers\Api\V1\Quotation\QuotationController;
use App\Http\Controllers\Api\V1\Quotation\QuotationAcceptanceController;
use App\Http\Controllers\Api\V1\Quotation\QuotationDiscussionController;
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

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
