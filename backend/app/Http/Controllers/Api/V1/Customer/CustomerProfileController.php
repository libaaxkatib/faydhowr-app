<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Customer\UpdateCustomerProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\UpdateCustomerProfileRequest;
use App\Http\Resources\Api\V1\Customer\CustomerProfileResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CustomerProfileController extends Controller
{
    public function show(Request $request, GetCustomerProfileAction $getCustomerProfile): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve customer profile.',
                'CUSTOMER_PROFILE_FETCH_FAILED',
                500,
            );
        }

        if ($profile === null) {
            return ApiResponse::error(
                'Customer profile not found.',
                'CUSTOMER_PROFILE_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Customer profile retrieved successfully.',
            new CustomerProfileResource($profile),
        );
    }

    public function update(
        UpdateCustomerProfileRequest $request,
        UpdateCustomerProfileAction $updateCustomerProfile,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $updateCustomerProfile->handle($user, $request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update customer profile.',
                'CUSTOMER_PROFILE_UPDATE_FAILED',
                500,
            );
        }

        if ($profile === null) {
            return ApiResponse::error(
                'Customer profile not found.',
                'CUSTOMER_PROFILE_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Customer profile updated successfully.',
            new CustomerProfileResource($profile),
        );
    }
}
