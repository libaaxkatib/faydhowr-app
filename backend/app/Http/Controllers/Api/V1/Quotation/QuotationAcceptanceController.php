<?php

namespace App\Http\Controllers\Api\V1\Quotation;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Quotation\AcceptQuotationAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Quotation\QuotationResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class QuotationAcceptanceController extends Controller
{
    public function store(
        Request $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        AcceptQuotationAction $acceptQuotation,
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

            $acceptedQuotation = $acceptQuotation->handle($profile, $quotation);
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Quotation not found.',
                'QUOTATION_NOT_FOUND',
                404,
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to accept quotation.',
                'QUOTATION_ACCEPTANCE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Quotation accepted successfully.',
            new QuotationResource($acceptedQuotation),
        );
    }
}
