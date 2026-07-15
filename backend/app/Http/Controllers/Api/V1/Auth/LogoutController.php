<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\LogoutCustomerAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class LogoutController extends Controller
{
    public function store(Request $request, LogoutCustomerAction $logoutCustomer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $logoutCustomer->handle($user);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Logout failed.', 'LOGOUT_FAILED', 500);
        }

        return ApiResponse::success('Logout successful.');
    }
}
