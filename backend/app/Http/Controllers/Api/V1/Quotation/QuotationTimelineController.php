<?php

namespace App\Http\Controllers\Api\V1\Quotation;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Quotation\GetQuotationTimelineAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Quotation\QuotationTimelineEventResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class QuotationTimelineController extends Controller
{
    public function index(
        Request $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        GetQuotationTimelineAction $getQuotationTimeline,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return ApiResponse::error(
                    'Customer profile not found.',
                    'CUSTOMER_PROFILE_NOT_FOUND',
                    404,
                );
            }

            $result = $getQuotationTimeline->handle($profile, $quotation);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve quotation timeline.',
                'QUOTATION_TIMELINE_FETCH_FAILED',
                500,
            );
        }

        if ($result === null) {
            return ApiResponse::error(
                'Quotation not found.',
                'QUOTATION_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Quotation timeline retrieved successfully.',
            [
                'quotation_number' => $result['quotation']->quotation_number,
                'status' => $result['quotation']->status->value,
                'events' => QuotationTimelineEventResource::collection($result['events']),
            ],
        );
    }
}
