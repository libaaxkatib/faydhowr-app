<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LoginCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\EmailLoginRequest;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class LoginController extends Controller
{
    public function store(EmailLoginRequest $request, LoginCustomerAction $loginCustomer): JsonResponse
    {
        if (config('auth_features.email_login') !== true) {
            return ApiResponse::error(
                'Email login is currently unavailable.',
                'AUTH_METHOD_DISABLED',
                403,
            );
        }

        try {
            $login = $loginCustomer->handle($request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Login failed.', 'LOGIN_FAILED', 500);
        }

        if ($login === null) {
            return ApiResponse::error('Invalid email or password.', 'INVALID_CREDENTIALS', 401);
        }

        return ApiResponse::success('Login successful.', new AuthenticatedUserResource($login));
    }
}
