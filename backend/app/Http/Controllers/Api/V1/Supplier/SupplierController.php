<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Actions\Supplier\CreateSupplierAction;
use App\Actions\Supplier\DeleteSupplierAction;
use App\Actions\Supplier\GetSupplierAction;
use App\Actions\Supplier\ListSuppliersAction;
use App\Actions\Supplier\UpdateSupplierAction;
use App\Enums\SupplierStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Supplier\StoreSupplierRequest;
use App\Http\Requests\Api\V1\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Api\V1\Supplier\SupplierResource;
use App\Models\Supplier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SupplierController extends Controller
{
    public function index(
        Request $request,
        ListSuppliersAction $listSuppliers,
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

        try {
            $suppliers = $listSuppliers->handle(
                $status,
                $this->requestedSearch($request),
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve suppliers.',
                'SUPPLIERS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Suppliers retrieved successfully.',
            [
                'items' => SupplierResource::collection($suppliers->getCollection()),
                'pagination' => [
                    'current_page' => $suppliers->currentPage(),
                    'per_page' => $suppliers->perPage(),
                    'total' => $suppliers->total(),
                    'last_page' => $suppliers->lastPage(),
                ],
            ],
        );
    }

    public function show(
        int $supplier,
        GetSupplierAction $getSupplier,
    ): JsonResponse {
        try {
            $catalogSupplier = $getSupplier->handle($supplier);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve supplier.',
                'SUPPLIER_FETCH_FAILED',
                500,
            );
        }

        if ($catalogSupplier === null) {
            return ApiResponse::error(
                'Supplier not found.',
                'SUPPLIER_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Supplier retrieved successfully.',
            new SupplierResource($catalogSupplier),
        );
    }

    public function store(
        StoreSupplierRequest $request,
        CreateSupplierAction $createSupplier,
    ): JsonResponse {
        try {
            $supplier = $createSupplier->handle($request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create supplier.',
                'SUPPLIER_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Supplier created successfully.',
            new SupplierResource($supplier),
            201,
        );
    }

    public function update(
        UpdateSupplierRequest $request,
        Supplier $supplier,
        UpdateSupplierAction $updateSupplier,
    ): JsonResponse {
        try {
            $updatedSupplier = $updateSupplier->handle($supplier, $request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update supplier.',
                'SUPPLIER_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Supplier updated successfully.',
            new SupplierResource($updatedSupplier),
        );
    }

    public function destroy(
        Supplier $supplier,
        DeleteSupplierAction $deleteSupplier,
    ): JsonResponse {
        try {
            $deleteSupplier->handle($supplier);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to delete supplier.',
                'SUPPLIER_DELETE_FAILED',
                500,
            );
        }

        return ApiResponse::success('Supplier deleted successfully.');
    }

    private function requestedStatus(Request $request): SupplierStatus|null|false
    {
        if (! $request->filled('status')) {
            return null;
        }

        $status = SupplierStatus::tryFrom((string) $request->query('status'));

        return $status ?? false;
    }

    private function requestedSearch(Request $request): ?string
    {
        if (! $request->filled('search')) {
            return null;
        }

        return trim((string) $request->query('search'));
    }
}
