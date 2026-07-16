<?php

namespace App\Http\Controllers\Api\V1\Checkout;

use App\Actions\Checkout\CheckoutAction;
use App\Actions\Customer\GetCustomerProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Checkout\CheckoutRequest;
use App\Http\Resources\Api\V1\Checkout\CheckoutResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Throwable;

class CheckoutController extends Controller
{
    public function store(
        CheckoutRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        CheckoutAction $checkout,
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

            $summary = $checkout->handle(
                $profile,
                (int) $request->validated('address_id'),
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to process checkout.',
                'CHECKOUT_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Checkout summary generated successfully.',
            new CheckoutResource($summary),
        );
    }

    private function domainError(string $code): JsonResponse
    {
        return match ($code) {
            'CART_EMPTY' => ApiResponse::error(
                'The cart is empty.',
                'CART_EMPTY',
                422,
            ),
            'ADDRESS_NOT_FOUND' => ApiResponse::error(
                'Customer address not found.',
                'ADDRESS_NOT_FOUND',
                404,
            ),
            'PRODUCT_INACTIVE' => ApiResponse::error(
                'One or more cart products are inactive.',
                'VALIDATION_ERROR',
                422,
            ),
            'INSUFFICIENT_STOCK' => ApiResponse::error(
                'One or more cart products have insufficient stock.',
                'VALIDATION_ERROR',
                422,
            ),
            default => ApiResponse::error(
                'Checkout validation failed.',
                'VALIDATION_ERROR',
                422,
            ),
        };
    }
}
