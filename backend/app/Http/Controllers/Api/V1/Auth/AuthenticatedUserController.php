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
        /** @var User $user */
        $user = $request->user();

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
