<?php

namespace App\Http\Controllers\Api\V1\Quotation;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Quotation\CancelQuotationByCustomerAction;
use App\Actions\Quotation\CreateQuotationAction;
use App\Actions\Quotation\GetCustomerQuotationAction;
use App\Actions\Quotation\ListCustomerQuotationsAction;
use App\Actions\Quotation\SubmitQuotationAction;
use App\Actions\Quotation\UpdateQuotationDraftAction;
use App\Enums\QuotationStatus;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Exceptions\Quotation\QuotationNotEditableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Quotation\StoreQuotationRequest;
use App\Http\Requests\Api\V1\Quotation\UpdateQuotationRequest;
use App\Http\Resources\Api\V1\Quotation\QuotationResource;
use App\Models\CustomerProfile;
use App\Models\Quotation;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class QuotationController extends Controller
{
    public function index(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        ListCustomerQuotationsAction $listCustomerQuotations,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $status = $this->requestedStatus($request);

        if ($status === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['status' => ['The selected status is invalid.']],
            );
        }

        $bookingId = $this->requestedBookingId($request);

        if ($bookingId === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['booking_id' => ['The booking id must be an integer.']],
            );
        }

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $quotations = $listCustomerQuotations->handle(
                $profile,
                $status,
                $bookingId,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve quotations.',
                'QUOTATIONS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Quotations retrieved successfully.',
            [
                'items' => QuotationResource::collection($quotations->getCollection()),
                'pagination' => [
                    'current_page' => $quotations->currentPage(),
                    'per_page' => $quotations->perPage(),
                    'total' => $quotations->total(),
                    'last_page' => $quotations->lastPage(),
                ],
            ],
        );
    }

    public function store(
        StoreQuotationRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        CreateQuotationAction $createQuotation,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $quotation = $createQuotation->handle($profile, $request->validated());
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Booking not found.',
                'BOOKING_NOT_FOUND',
                404,
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create quotation.',
                'QUOTATION_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Quotation created successfully.',
            new QuotationResource($quotation),
            201,
        );
    }

    public function show(
        Request $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        GetCustomerQuotationAction $getCustomerQuotation,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerQuotation = $getCustomerQuotation->handle($profile, $quotation);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve quotation.',
                'QUOTATION_FETCH_FAILED',
                500,
            );
        }

        if ($customerQuotation === null) {
            return $this->quotationNotFound();
        }

        return ApiResponse::success(
            'Quotation retrieved successfully.',
            new QuotationResource($customerQuotation),
        );
    }

    public function update(
        UpdateQuotationRequest $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        UpdateQuotationDraftAction $updateQuotationDraft,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $updated = $updateQuotationDraft->handle($profile, $quotation, $request->validated());
        } catch (ModelNotFoundException) {
            return $this->quotationNotFound();
        } catch (QuotationNotEditableException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_NOT_EDITABLE', 409);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update quotation.',
                'QUOTATION_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Quotation updated successfully.',
            new QuotationResource($updated),
        );
    }

    public function submit(
        Request $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        SubmitQuotationAction $submitQuotation,
    ): JsonResponse {
        return $this->transition(
            $request,
            $getCustomerProfile,
            fn (CustomerProfile $profile) => $submitQuotation->handle($profile, $quotation),
            'Quotation submitted successfully.',
            'QUOTATION_SUBMIT_FAILED',
            'Failed to submit quotation.',
        );
    }

    public function cancel(
        Request $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        CancelQuotationByCustomerAction $cancelQuotation,
    ): JsonResponse {
        return $this->transition(
            $request,
            $getCustomerProfile,
            fn (CustomerProfile $profile) => $cancelQuotation->handle($profile, $quotation),
            'Quotation cancelled successfully.',
            'QUOTATION_CANCEL_FAILED',
            'Failed to cancel quotation.',
        );
    }

    /**
     * @param  callable(CustomerProfile): Quotation  $handler
     */
    private function transition(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        callable $handler,
        string $successMessage,
        string $failureCode,
        string $failureMessage,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $result = $handler($profile);
        } catch (ModelNotFoundException) {
            return $this->quotationNotFound();
        } catch (QuotationInvalidStateException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_INVALID_STATE', 409);
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error($failureMessage, $failureCode, 500);
        }

        return ApiResponse::success($successMessage, new QuotationResource($result));
    }

    private function profileNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Customer profile not found.',
            'CUSTOMER_PROFILE_NOT_FOUND',
            404,
        );
    }

    private function quotationNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Quotation not found.',
            'QUOTATION_NOT_FOUND',
            404,
        );
    }

    private function requestedStatus(Request $request): QuotationStatus|false|null
    {
        $status = $request->query('status');

        if ($status === null || $status === '') {
            return null;
        }

        return is_string($status) ? QuotationStatus::tryFrom($status) ?? false : false;
    }

    private function requestedBookingId(Request $request): int|false|null
    {
        $bookingId = $request->query('booking_id');

        if ($bookingId === null || $bookingId === '') {
            return null;
        }

        return filter_var($bookingId, FILTER_VALIDATE_INT) === false
            ? false
            : (int) $bookingId;
    }
}
