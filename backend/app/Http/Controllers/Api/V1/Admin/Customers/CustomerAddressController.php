<?php

namespace App\Http\Controllers\Api\V1\Admin\Customers;

use App\Contracts\Customer\Services\AddressServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Customers\StoreAddressRequest;
use App\Http\Requests\Api\V1\Admin\Customers\UpdateAddressRequest;
use App\Http\Resources\Api\V1\Admin\Customers\AddressResource;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CustomerAddressController extends Controller
{
    public function __construct(private AddressServiceInterface $addresses) {}

    public function index(CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('view', $customer);

        return ApiResponse::success(
            'Customer addresses retrieved successfully.',
            AddressResource::collection($this->addresses->list($customer)),
        );
    }

    public function store(StoreAddressRequest $request, CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('update', $customer);

        $address = $this->addresses->create($customer, $request->toData(), $request->user());

        return ApiResponse::success(
            'Customer address created successfully.',
            new AddressResource($address),
            201,
        );
    }

    public function update(UpdateAddressRequest $request, CustomerProfile $customer, CustomerAddress $address): JsonResponse
    {
        Gate::authorize('update', $customer);

        $updated = $this->addresses->update($customer, $address, $request->toData(), $request->user());

        return ApiResponse::success(
            'Customer address updated successfully.',
            new AddressResource($updated),
        );
    }

    public function setDefault(CustomerProfile $customer, CustomerAddress $address): JsonResponse
    {
        Gate::authorize('update', $customer);

        $updated = $this->addresses->setDefault($customer, $address, request()->user());

        return ApiResponse::success(
            'Default address updated successfully.',
            new AddressResource($updated),
        );
    }

    public function deactivate(CustomerProfile $customer, CustomerAddress $address): JsonResponse
    {
        Gate::authorize('update', $customer);

        $updated = $this->addresses->deactivate($customer, $address, request()->user());

        return ApiResponse::success(
            'Customer address deactivated successfully.',
            new AddressResource($updated),
        );
    }
}
