<?php

namespace App\Http\Controllers\Api\V1\StoreOrder;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\StoreOrder\CancelStoreOrderAction;
use App\Actions\StoreOrder\CreateStoreOrderAction;
use App\Actions\StoreOrder\GetCustomerStoreOrderAction;
use App\Actions\StoreOrder\ListCustomerStoreOrdersAction;
use App\Enums\PaymentMethod;
use App\Enums\StoreOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOrder\CancelStoreOrderRequest;
use App\Http\Requests\Api\V1\StoreOrder\CreateStoreOrderRequest;
use App\Http\Resources\Api\V1\StoreOrder\StoreOrderResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StoreOrderController extends Controller
{
    public function index(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        ListCustomerStoreOrdersAction $listCustomerStoreOrders,
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

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $storeOrders = $listCustomerStoreOrders->handle(
                $profile,
                $status,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve store orders.',
                'STORE_ORDERS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Store orders retrieved successfully.',
            [
                'items' => StoreOrderResource::collection($storeOrders->getCollection()),
                'pagination' => [
                    'current_page' => $storeOrders->currentPage(),
                    'per_page' => $storeOrders->perPage(),
                    'total' => $storeOrders->total(),
                    'last_page' => $storeOrders->lastPage(),
                ],
            ],
        );
    }

    public function store(
        CreateStoreOrderRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        CreateStoreOrderAction $createStoreOrder,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $storeOrder = $createStoreOrder->handle(
                $profile,
                (int) $request->validated('address_id'),
                PaymentMethod::from((string) $request->validated('payment_method')),
                $request->validated('notes'),
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create store order.',
                'STORE_ORDER_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Store order created successfully.',
            new StoreOrderResource($storeOrder),
            201,
        );
    }

    public function show(
        Request $request,
        int $storeOrder,
        GetCustomerProfileAction $getCustomerProfile,
        GetCustomerStoreOrderAction $getCustomerStoreOrder,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerStoreOrder = $getCustomerStoreOrder->handle($profile, $storeOrder);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve store order.',
                'STORE_ORDER_FETCH_FAILED',
                500,
            );
        }

        if ($customerStoreOrder === null) {
            return ApiResponse::error(
                'Store order not found.',
                'STORE_ORDER_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Store order retrieved successfully.',
            new StoreOrderResource($customerStoreOrder),
        );
    }

    public function cancel(
        CancelStoreOrderRequest $request,
        int $storeOrder,
        GetCustomerProfileAction $getCustomerProfile,
        CancelStoreOrderAction $cancelStoreOrder,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerStoreOrder = $cancelStoreOrder->handle(
                $profile,
                $storeOrder,
                $request->validated('cancellation_reason'),
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to cancel store order.',
                'STORE_ORDER_CANCELLATION_FAILED',
                500,
            );
        }

        if ($customerStoreOrder === null) {
            return ApiResponse::error(
                'Store order not found.',
                'STORE_ORDER_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Store order cancelled successfully.',
            new StoreOrderResource($customerStoreOrder),
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
                $code,
                'VALIDATION_ERROR',
                422,
            ),
        };
    }

    private function requestedStatus(Request $request): StoreOrderStatus|false|null
    {
        $status = $request->query('status');

        if ($status === null || $status === '') {
            return null;
        }

        return is_string($status) ? StoreOrderStatus::tryFrom($status) ?? false : false;
    }
}
