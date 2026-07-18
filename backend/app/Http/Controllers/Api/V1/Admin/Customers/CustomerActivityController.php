<?php

namespace App\Http\Controllers\Api\V1\Admin\Customers;

use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Customers\ListActivityLogsRequest;
use App\Http\Resources\Api\V1\Admin\Customers\ActivityResource;
use App\Models\CustomerProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CustomerActivityController extends Controller
{
    public function __construct(private CustomerActivityServiceInterface $activities) {}

    public function timeline(CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('view', $customer);

        $paginator = $this->activities->timeline($customer);

        return ApiResponse::success(
            'Customer timeline retrieved successfully.',
            ActivityResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function activityLogs(ListActivityLogsRequest $request, CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('view', $customer);

        $validated = $request->validated();

        $paginator = $this->activities->activityLogs(
            $customer,
            $validated['event_type'] ?? null,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            (int) ($validated['per_page'] ?? 25),
        );

        return ApiResponse::success(
            'Customer activity logs retrieved successfully.',
            ActivityResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }
}
