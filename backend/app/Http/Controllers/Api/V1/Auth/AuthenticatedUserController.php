<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GetAuthenticatedCustomerAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AuthenticatedUserController extends Controller
{
    public function show(Request $request, GetAuthenticatedCustomerAction $getAuthenticatedCustomer): JsonResponse
    {
        $user = $request->user();

        // This is the CUSTOMER profile route. A valid token that belongs to someone else (an admin token) is authenticated but not a customer: answer 403
        // instead of letting the customer action fail on a wrong type and come back as an internal error.
        if (! $user instanceof User) {
            return ApiResponse::error(
                'This endpoint is for customer accounts.',
                'FORBIDDEN',
                403,
            );
        }

        try {
            $authenticatedUser = $getAuthenticatedCustomer->handle($user);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve authenticated user.',
                'AUTH_USER_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Authenticated user retrieved successfully.',
            new AuthenticatedUserResource($authenticatedUser),
        );
    }
}
