<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Order\CancelOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\CancelOrderRequest;
use App\Http\Resources\Api\V1\Order\OrderResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Throwable;

class OrderLifecycleController extends Controller
{
    public function cancel(
        CancelOrderRequest $request,
        int $order,
        GetCustomerProfileAction $getCustomerProfile,
        CancelOrderAction $cancelOrder,
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

            $customerOrder = $cancelOrder->handle(
                $profile,
                $order,
                $request->validated('cancellation_reason'),
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to cancel order.',
                'ORDER_CANCELLATION_FAILED',
                500,
            );
        }

        if ($customerOrder === null) {
            return ApiResponse::error(
                'Order not found.',
                'ORDER_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Order cancelled successfully.',
            new OrderResource($customerOrder),
        );
    }
}
