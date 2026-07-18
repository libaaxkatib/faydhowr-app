<?php

namespace App\Http\Controllers\Api\V1\Admin\Customers;

use App\Contracts\Customer\Services\CustomerServiceInterface;
use App\Exceptions\Customer\CustomerAlreadyDeletedException;
use App\Exceptions\Customer\CustomerEmailTakenException;
use App\Exceptions\Customer\CustomerInvalidStatusException;
use App\Exceptions\Customer\CustomerNotDeletedException;
use App\Exceptions\Customer\CustomerNotFoundException;
use App\Exceptions\Customer\CustomerPhoneTakenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Customers\ListCustomersRequest;
use App\Http\Requests\Api\V1\Admin\Customers\RestoreCustomerRequest;
use App\Http\Requests\Api\V1\Admin\Customers\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Admin\Customers\UpdateCustomerRequest;
use App\Http\Requests\Api\V1\Admin\Customers\UpdateCustomerStatusRequest;
use App\Http\Resources\Api\V1\Admin\Customers\CustomerResource;
use App\Models\CustomerProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function __construct(private CustomerServiceInterface $customers) {}

    public function index(ListCustomersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', CustomerProfile::class);

        $paginator = $this->customers->paginate($request->toFilters());

        return ApiResponse::success(
            'Customers retrieved successfully.',
            CustomerResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        Gate::authorize('create', CustomerProfile::class);

        try {
            $profile = $this->customers->create($request->toData(), $request->user());
        } catch (CustomerPhoneTakenException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_PHONE_TAKEN', 422);
        } catch (CustomerEmailTakenException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_EMAIL_TAKEN', 422);
        }

        return ApiResponse::success(
            'Customer created successfully.',
            new CustomerResource($profile),
            201,
        );
    }

    public function show(CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('view', $customer);

        try {
            $result = $this->customers->show($customer->id, $customer->trashed());
        } catch (CustomerNotFoundException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_NOT_FOUND', 404);
        }

        $profile = $result['profile'];
        $profile->summary_counts = $result['summary'];

        return ApiResponse::success(
            'Customer retrieved successfully.',
            new CustomerResource($profile),
        );
    }

    public function update(UpdateCustomerRequest $request, CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('update', $customer);

        try {
            $profile = $this->customers->update($customer, $request->toData(), $request->user());
        } catch (CustomerPhoneTakenException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_PHONE_TAKEN', 422);
        } catch (CustomerEmailTakenException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_EMAIL_TAKEN', 422);
        }

        return ApiResponse::success(
            'Customer updated successfully.',
            new CustomerResource($profile),
        );
    }

    public function updateStatus(UpdateCustomerStatusRequest $request, CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('updateStatus', $customer);

        try {
            $profile = $this->customers->updateStatus($customer, $request->toData(), $request->user());
        } catch (CustomerAlreadyDeletedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_ALREADY_DELETED', 422);
        } catch (CustomerInvalidStatusException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_INVALID_STATUS', 422);
        }

        return ApiResponse::success(
            'Customer status updated successfully.',
            new CustomerResource($profile),
        );
    }

    public function destroy(CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('delete', $customer);

        try {
            $this->customers->delete($customer, request()->user());
        } catch (CustomerAlreadyDeletedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_ALREADY_DELETED', 422);
        }

        return ApiResponse::success('Customer deleted successfully.');
    }

    public function restore(RestoreCustomerRequest $request, int $customer): JsonResponse
    {
        $profile = CustomerProfile::withTrashed()->find($customer);

        if ($profile === null) {
            return ApiResponse::error('Customer was not found.', 'CUSTOMER_NOT_FOUND', 404);
        }

        Gate::authorize('restore', $profile);

        try {
            $restored = $this->customers->restore($profile, $request->toData(), $request->user());
        } catch (CustomerNotDeletedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_NOT_DELETED', 422);
        } catch (CustomerInvalidStatusException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_INVALID_STATUS', 422);
        }

        return ApiResponse::success(
            'Customer restored successfully.',
            new CustomerResource($restored),
        );
    }
}
