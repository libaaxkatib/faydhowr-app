<?php

namespace App\Http\Controllers\Api\V1\GoodsReceipt;

use App\Actions\GoodsReceipt\CreateGoodsReceiptAction;
use App\Actions\GoodsReceipt\GetGoodsReceiptAction;
use App\Actions\GoodsReceipt\ListGoodsReceiptsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GoodsReceipt\StoreGoodsReceiptRequest;
use App\Http\Resources\Api\V1\GoodsReceipt\GoodsReceiptResource;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class GoodsReceiptController extends Controller
{
    public function index(
        Request $request,
        ListGoodsReceiptsAction $listGoodsReceipts,
    ): JsonResponse {
        $supplierId = $this->requestedInteger($request, 'supplier_id');

        if ($supplierId === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['supplier_id' => ['The supplier id must be an integer.']],
            );
        }

        $purchaseOrderId = $this->requestedInteger($request, 'purchase_order_id');

        if ($purchaseOrderId === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['purchase_order_id' => ['The purchase order id must be an integer.']],
            );
        }

        try {
            $goodsReceipts = $listGoodsReceipts->handle(
                $supplierId,
                $purchaseOrderId,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve goods receipts.',
                'GOODS_RECEIPTS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Goods receipts retrieved successfully.',
            [
                'items' => GoodsReceiptResource::collection($goodsReceipts->getCollection()),
                'pagination' => [
                    'current_page' => $goodsReceipts->currentPage(),
                    'per_page' => $goodsReceipts->perPage(),
                    'total' => $goodsReceipts->total(),
                    'last_page' => $goodsReceipts->lastPage(),
                ],
            ],
        );
    }

    public function show(
        int $goodsReceipt,
        GetGoodsReceiptAction $getGoodsReceipt,
    ): JsonResponse {
        try {
            $receipt = $getGoodsReceipt->handle($goodsReceipt);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve goods receipt.',
                'GOODS_RECEIPT_FETCH_FAILED',
                500,
            );
        }

        if ($receipt === null) {
            return ApiResponse::error(
                'Goods receipt not found.',
                'GOODS_RECEIPT_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Goods receipt retrieved successfully.',
            new GoodsReceiptResource($receipt),
        );
    }

    public function store(
        StoreGoodsReceiptRequest $request,
        CreateGoodsReceiptAction $createGoodsReceipt,
    ): JsonResponse {
        try {
            $goodsReceipt = $createGoodsReceipt->handle($request->validated());
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create goods receipt.',
                'GOODS_RECEIPT_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Goods receipt created successfully.',
            new GoodsReceiptResource($goodsReceipt),
            201,
        );
    }

    private function requestedInteger(Request $request, string $key): int|null|false
    {
        if (! $request->filled($key)) {
            return null;
        }

        if (! is_numeric($request->query($key))) {
            return false;
        }

        return (int) $request->query($key);
    }
}
