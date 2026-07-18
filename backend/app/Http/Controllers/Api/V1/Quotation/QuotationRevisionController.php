<?php

namespace App\Http\Controllers\Api\V1\Quotation;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Quotation\ListQuotationRevisionsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Quotation\QuotationRevisionResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class QuotationRevisionController extends Controller
{
    public function index(
        Request $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        ListQuotationRevisionsAction $listQuotationRevisions,
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

            $result = $listQuotationRevisions->handle($profile, $quotation);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve quotation revisions.',
                'QUOTATION_REVISIONS_FETCH_FAILED',
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
            'Quotation revisions retrieved successfully.',
            [
                'quotation_number' => $result['quotation']->quotation_number,
                'latest_version' => $result['quotation']->latestRevision?->version_number,
                'revisions' => QuotationRevisionResource::collection($result['revisions']),
            ],
        );
    }
}
