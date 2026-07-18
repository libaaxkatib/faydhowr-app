<?php

namespace App\Http\Controllers\Api\V1\Admin\Customers;

use App\Contracts\Customer\Services\AttachmentServiceInterface;
use App\Exceptions\Customer\CustomerNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Customers\StoreAttachmentRequest;
use App\Http\Resources\Api\V1\Admin\Customers\AttachmentResource;
use App\Models\CustomerAttachment;
use App\Models\CustomerProfile;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerAttachmentController extends Controller
{
    public function __construct(private AttachmentServiceInterface $attachments) {}

    public function index(CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('manageAttachments', $customer);

        $paginator = $this->attachments->paginate($customer);

        return ApiResponse::success(
            'Customer attachments retrieved successfully.',
            AttachmentResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function store(StoreAttachmentRequest $request, CustomerProfile $customer): JsonResponse
    {
        Gate::authorize('manageAttachments', $customer);

        $attachment = $this->attachments->store(
            $customer,
            $request->file('file'),
            $request->user(),
        );

        return ApiResponse::success(
            'Customer attachment uploaded successfully.',
            new AttachmentResource($attachment),
            201,
        );
    }

    public function download(CustomerProfile $customer, CustomerAttachment $attachment): StreamedResponse|JsonResponse
    {
        Gate::authorize('manageAttachments', $customer);

        try {
            $found = $this->attachments->find($customer, $attachment->id);
        } catch (CustomerNotFoundException $exception) {
            return ApiResponse::error($exception->getMessage(), 'CUSTOMER_NOT_FOUND', 404);
        }

        return $this->attachments->download($found);
    }

    public function destroy(CustomerProfile $customer, CustomerAttachment $attachment): JsonResponse
    {
        Gate::authorize('manageAttachments', $customer);

        $this->attachments->delete($customer, $attachment, request()->user());

        return ApiResponse::success('Customer attachment deleted successfully.');
    }
}
