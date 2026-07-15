<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedUserController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegistrationController;
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

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
