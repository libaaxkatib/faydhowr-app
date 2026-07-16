<?php

namespace App\Http\Controllers\Api\V1\PurchaseOrder;

use App\Actions\PurchaseOrder\ApprovePurchaseOrderAction;
use App\Actions\PurchaseOrder\CancelPurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Actions\PurchaseOrder\GetPurchaseOrderAction;
use App\Actions\PurchaseOrder\ListPurchaseOrdersAction;
use App\Actions\PurchaseOrder\SubmitPurchaseOrderAction;
use App\Actions\PurchaseOrder\UpdatePurchaseOrderAction;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\Api\V1\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Resources\Api\V1\PurchaseOrder\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PurchaseOrderController extends Controller
{
    public function index(
        Request $request,
        ListPurchaseOrdersAction $listPurchaseOrders,
    ): JsonResponse {
        $status = $this->requestedStatus($request);

        if ($status === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['status' => ['The selected status is invalid.']],
            );
        }

        $supplierId = $this->requestedSupplierId($request);

        if ($supplierId === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['supplier_id' => ['The supplier id must be an integer.']],
            );
        }

        try {
            $purchaseOrders = $listPurchaseOrders->handle(
                $status,
                $supplierId,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve purchase orders.',
                'PURCHASE_ORDERS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Purchase orders retrieved successfully.',
            [
                'items' => PurchaseOrderResource::collection($purchaseOrders->getCollection()),
                'pagination' => [
                    'current_page' => $purchaseOrders->currentPage(),
                    'per_page' => $purchaseOrders->perPage(),
                    'total' => $purchaseOrders->total(),
                    'last_page' => $purchaseOrders->lastPage(),
                ],
            ],
        );
    }

    public function show(
        int $purchaseOrder,
        GetPurchaseOrderAction $getPurchaseOrder,
    ): JsonResponse {
        try {
            $order = $getPurchaseOrder->handle($purchaseOrder);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve purchase order.',
                'PURCHASE_ORDER_FETCH_FAILED',
                500,
            );
        }

        if ($order === null) {
            return ApiResponse::error(
                'Purchase order not found.',
                'PURCHASE_ORDER_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Purchase order retrieved successfully.',
            new PurchaseOrderResource($order),
        );
    }

    public function store(
        StorePurchaseOrderRequest $request,
        CreatePurchaseOrderAction $createPurchaseOrder,
    ): JsonResponse {
        try {
            $purchaseOrder = $createPurchaseOrder->handle($request->validated());
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create purchase order.',
                'PURCHASE_ORDER_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Purchase order created successfully.',
            new PurchaseOrderResource($purchaseOrder),
            201,
        );
    }

    public function update(
        UpdatePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        UpdatePurchaseOrderAction $updatePurchaseOrder,
    ): JsonResponse {
        try {
            $updated = $updatePurchaseOrder->handle($purchaseOrder, $request->validated());
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update purchase order.',
                'PURCHASE_ORDER_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Purchase order updated successfully.',
            new PurchaseOrderResource($updated),
        );
    }

    public function submit(
        PurchaseOrder $purchaseOrder,
        SubmitPurchaseOrderAction $submitPurchaseOrder,
    ): JsonResponse {
        try {
            $submitted = $submitPurchaseOrder->handle($purchaseOrder);
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to submit purchase order.',
                'PURCHASE_ORDER_SUBMIT_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Purchase order submitted successfully.',
            new PurchaseOrderResource($submitted),
        );
    }

    public function approve(
        PurchaseOrder $purchaseOrder,
        ApprovePurchaseOrderAction $approvePurchaseOrder,
    ): JsonResponse {
        try {
            $approved = $approvePurchaseOrder->handle($purchaseOrder);
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to approve purchase order.',
                'PURCHASE_ORDER_APPROVE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Purchase order approved successfully.',
            new PurchaseOrderResource($approved),
        );
    }

    public function cancel(
        PurchaseOrder $purchaseOrder,
        CancelPurchaseOrderAction $cancelPurchaseOrder,
    ): JsonResponse {
        try {
            $cancelled = $cancelPurchaseOrder->handle($purchaseOrder);
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to cancel purchase order.',
                'PURCHASE_ORDER_CANCEL_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Purchase order cancelled successfully.',
            new PurchaseOrderResource($cancelled),
        );
    }

    private function requestedStatus(Request $request): PurchaseOrderStatus|null|false
    {
        if (! $request->filled('status')) {
            return null;
        }

        $status = PurchaseOrderStatus::tryFrom((string) $request->query('status'));

        return $status ?? false;
    }

    private function requestedSupplierId(Request $request): int|null|false
    {
        if (! $request->filled('supplier_id')) {
            return null;
        }

        if (! is_numeric($request->query('supplier_id'))) {
            return false;
        }

        return (int) $request->query('supplier_id');
    }
}
