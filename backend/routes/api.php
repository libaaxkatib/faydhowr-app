<?php

use App\Http\Controllers\Api\V1\Auth\RegistrationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function (): void {
    Route::post('register', [RegistrationController::class, 'store'])
        ->name('api.v1.auth.register');
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
