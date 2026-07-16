<?php

namespace App\Http\Controllers\Api\V1\Quotation;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Quotation\CreateQuotationDiscussionMessageAction;
use App\Actions\Quotation\ListQuotationDiscussionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Quotation\StoreQuotationDiscussionMessageRequest;
use App\Http\Resources\Api\V1\Quotation\QuotationDiscussionMessageResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class QuotationDiscussionController extends Controller
{
    public function index(
        Request $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        ListQuotationDiscussionAction $listQuotationDiscussion,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $messages = $listQuotationDiscussion->handle($profile, $quotation);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve quotation discussion.',
                'QUOTATION_DISCUSSION_FETCH_FAILED',
                500,
            );
        }

        if ($messages === null) {
            return $this->quotationNotFound();
        }

        return ApiResponse::success(
            'Quotation discussion retrieved successfully.',
            QuotationDiscussionMessageResource::collection($messages),
        );
    }

    public function store(
        StoreQuotationDiscussionMessageRequest $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        CreateQuotationDiscussionMessageAction $createQuotationDiscussionMessage,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $message = $createQuotationDiscussionMessage->handle(
                $profile,
                $quotation,
                $request->validated(),
            );
        } catch (ModelNotFoundException) {
            return $this->quotationNotFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create quotation discussion message.',
                'QUOTATION_DISCUSSION_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Quotation discussion message created successfully.',
            new QuotationDiscussionMessageResource($message),
            201,
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

    private function quotationNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Quotation not found.',
            'QUOTATION_NOT_FOUND',
            404,
        );
    }
}
