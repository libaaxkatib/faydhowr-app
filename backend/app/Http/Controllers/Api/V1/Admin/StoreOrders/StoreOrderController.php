<?php

namespace App\Http\Controllers\Api\V1\Admin\StoreOrders;

use App\Actions\StoreOrder\AdvanceStoreOrderStatusAction;
use App\Actions\StoreOrder\GetAdminStoreOrderAction;
use App\Actions\StoreOrder\ListAdminStoreOrdersAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreOrders\ListAdminStoreOrdersRequest;
use App\Http\Requests\Api\V1\Admin\StoreOrders\UpdateStoreOrderStatusRequest;
use App\Http\Resources\Api\V1\Admin\StoreOrders\AdminStoreOrderResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Throwable;

class StoreOrderController extends Controller
{
    public function index(
        ListAdminStoreOrdersRequest $request,
        ListAdminStoreOrdersAction $listAdminStoreOrders,
    ): JsonResponse {
        $paginator = $listAdminStoreOrders->handle($request->toFilters());

        return ApiResponse::success(
            'Store orders retrieved successfully.',
            ['items' => AdminStoreOrderResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function show(
        int $storeOrder,
        GetAdminStoreOrderAction $getAdminStoreOrder,
    ): JsonResponse {
        $found = $getAdminStoreOrder->handle($storeOrder);

        if ($found === null) {
            return $this->notFound();
        }

        return ApiResponse::success(
            'Store order retrieved successfully.',
            new AdminStoreOrderResource($found),
        );
    }

    public function updateStatus(
        UpdateStoreOrderStatusRequest $request,
        int $storeOrder,
        AdvanceStoreOrderStatusAction $advanceStoreOrderStatus,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $updated = $advanceStoreOrderStatus->handle($admin, $storeOrder, $request->targetStatus());
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'STORE_ORDER_STATE_INVALID', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update store order status.',
                'STORE_ORDER_STATUS_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Store order status updated successfully.',
            new AdminStoreOrderResource($updated->load('customerProfile')),
        );
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error('Store order not found.', 'STORE_ORDER_NOT_FOUND', 404);
    }
}
