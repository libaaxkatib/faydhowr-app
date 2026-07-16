<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Actions\Customer\CreateCustomerAddressAction;
use App\Actions\Customer\GetCustomerAddressAction;
use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Customer\ListCustomerAddressesAction;
use App\Actions\Customer\UpdateCustomerAddressAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\StoreCustomerAddressRequest;
use App\Http\Requests\Api\V1\Customer\UpdateCustomerAddressRequest;
use App\Http\Resources\Api\V1\Customer\CustomerAddressResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CustomerAddressController extends Controller
{
    public function index(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        ListCustomerAddressesAction $listCustomerAddresses,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $addresses = $listCustomerAddresses->handle($profile);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve customer addresses.',
                'CUSTOMER_ADDRESSES_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Customer addresses retrieved successfully.',
            CustomerAddressResource::collection($addresses),
        );
    }

    public function store(
        StoreCustomerAddressRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        CreateCustomerAddressAction $createCustomerAddress,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $address = $createCustomerAddress->handle($profile, $request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create customer address.',
                'CUSTOMER_ADDRESS_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Customer address created successfully.',
            new CustomerAddressResource($address),
            201,
        );
    }

    public function show(
        Request $request,
        int $address,
        GetCustomerProfileAction $getCustomerProfile,
        GetCustomerAddressAction $getCustomerAddress,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerAddress = $getCustomerAddress->handle($profile, $address);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve customer address.',
                'CUSTOMER_ADDRESS_FETCH_FAILED',
                500,
            );
        }

        if ($customerAddress === null) {
            return $this->addressNotFound();
        }

        return ApiResponse::success(
            'Customer address retrieved successfully.',
            new CustomerAddressResource($customerAddress),
        );
    }

    public function update(
        UpdateCustomerAddressRequest $request,
        int $address,
        GetCustomerProfileAction $getCustomerProfile,
        UpdateCustomerAddressAction $updateCustomerAddress,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerAddress = $updateCustomerAddress->handle(
                $profile,
                $address,
                $request->validated(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update customer address.',
                'CUSTOMER_ADDRESS_UPDATE_FAILED',
                500,
            );
        }

        if ($customerAddress === null) {
            return $this->addressNotFound();
        }

        return ApiResponse::success(
            'Customer address updated successfully.',
            new CustomerAddressResource($customerAddress),
        );
    }

    private function profileNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Customer profile not found.',
            'CUSTOMER_PROFILE_NOT_FOUND',
            404,
        );
    }

    private function addressNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Customer address not found.',
            'CUSTOMER_ADDRESS_NOT_FOUND',
            404,
        );
    }
}
