<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Order\CreateOrderAction;
use App\Actions\Order\GetCustomerOrderAction;
use App\Actions\Order\ListCustomerOrdersAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Order\StoreOrderRequest;
use App\Http\Resources\Api\V1\Order\OrderResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class OrderController extends Controller
{
    public function index(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        ListCustomerOrdersAction $listCustomerOrders,
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

        $quotationId = $this->requestedQuotationId($request);

        if ($quotationId === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['quotation_id' => ['The quotation id must be an integer.']],
            );
        }

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $orders = $listCustomerOrders->handle(
                $profile,
                $status,
                $quotationId,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve orders.',
                'ORDERS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Orders retrieved successfully.',
            [
                'items' => OrderResource::collection($orders->getCollection()),
                'pagination' => [
                    'current_page' => $orders->currentPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'last_page' => $orders->lastPage(),
                ],
            ],
        );
    }

    public function store(
        StoreOrderRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        CreateOrderAction $createOrder,
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

            $order = $createOrder->handle($profile, $request->validated('quotation_id'));
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
                'Failed to create order.',
                'ORDER_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Order created successfully.',
            new OrderResource($order),
            201,
        );
    }

    public function show(
        Request $request,
        int $order,
        GetCustomerProfileAction $getCustomerProfile,
        GetCustomerOrderAction $getCustomerOrder,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerOrder = $getCustomerOrder->handle($profile, $order);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve order.',
                'ORDER_FETCH_FAILED',
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
            'Order retrieved successfully.',
            new OrderResource($customerOrder),
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

    private function requestedStatus(Request $request): OrderStatus|false|null
    {
        $status = $request->query('status');

        if ($status === null || $status === '') {
            return null;
        }

        return is_string($status) ? OrderStatus::tryFrom($status) ?? false : false;
    }

    private function requestedQuotationId(Request $request): int|false|null
    {
        $quotationId = $request->query('quotation_id');

        if ($quotationId === null || $quotationId === '') {
            return null;
        }

        return filter_var($quotationId, FILTER_VALIDATE_INT) === false
            ? false
            : (int) $quotationId;
    }
}
