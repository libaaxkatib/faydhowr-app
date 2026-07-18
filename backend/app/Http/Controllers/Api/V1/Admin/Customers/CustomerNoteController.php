<?php

namespace App\Http\Controllers\Api\V1\Admin\Customers;

use App\Contracts\Customer\Services\NoteServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Customers\StoreNoteRequest;
use App\Http\Resources\Api\V1\Admin\Customers\NoteResource;
use App\Models\CustomerProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class CustomerNoteController extends Controller
{
    public function __construct(private NoteServiceInterface $notes) {}

    public function index(CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('manageNotes', $customer);

        $paginator = $this->notes->paginate($customer);

        return ApiResponse::success(
            'Customer notes retrieved successfully.',
            NoteResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function store(StoreNoteRequest $request, CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('manageNotes', $customer);

        $note = $this->notes->create($customer, $request->toData(), $request->user());

        return ApiResponse::success(
            'Customer note created successfully.',
            new NoteResource($note),
            201,
        );
    }
}
