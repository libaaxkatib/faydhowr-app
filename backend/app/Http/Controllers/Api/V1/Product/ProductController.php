<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Actions\Product\CreateProductAction;
use App\Actions\Product\DeleteProductAction;
use App\Actions\Product\GetProductAction;
use App\Actions\Product\ListProductsAction;
use App\Actions\Product\UpdateProductAction;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Product\StoreProductRequest;
use App\Http\Requests\Api\V1\Product\UpdateProductRequest;
use App\Http\Resources\Api\V1\Product\ProductResource;
use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ProductController extends Controller
{
    public function index(
        Request $request,
        ListProductsAction $listProducts,
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

        $categoryId = $this->requestedCategoryId($request);

        if ($categoryId === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['category_id' => ['The category id must be an integer.']],
            );
        }

        $featured = $this->requestedFeatured($request);

        if ($featured === 'invalid') {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['featured' => ['The featured filter must be a boolean.']],
            );
        }

        $canFilterByStatus = $request->user('sanctum') !== null;

        try {
            $products = $listProducts->handle(
                $categoryId,
                $featured,
                $this->requestedSearch($request),
                $canFilterByStatus ? $status : null,
                $canFilterByStatus,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve products.',
                'PRODUCTS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Products retrieved successfully.',
            [
                'items' => ProductResource::collection($products->getCollection()),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                    'last_page' => $products->lastPage(),
                ],
            ],
        );
    }

    public function show(
        Request $request,
        int $product,
        GetProductAction $getProduct,
    ): JsonResponse {
        $canViewInactive = $request->user('sanctum') !== null;

        try {
            $catalogProduct = $getProduct->handle($product, $canViewInactive);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve product.',
                'PRODUCT_FETCH_FAILED',
                500,
            );
        }

        if ($catalogProduct === null) {
            return ApiResponse::error(
                'Product not found.',
                'PRODUCT_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Product retrieved successfully.',
            new ProductResource($catalogProduct),
        );
    }

    public function store(
        StoreProductRequest $request,
        CreateProductAction $createProduct,
    ): JsonResponse {
        try {
            $product = $createProduct->handle($request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create product.',
                'PRODUCT_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Product created successfully.',
            new ProductResource($product),
            201,
        );
    }

    public function update(
        UpdateProductRequest $request,
        Product $product,
        UpdateProductAction $updateProduct,
    ): JsonResponse {
        try {
            $updatedProduct = $updateProduct->handle($product, $request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update product.',
                'PRODUCT_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Product updated successfully.',
            new ProductResource($updatedProduct),
        );
    }

    public function destroy(
        Product $product,
        DeleteProductAction $deleteProduct,
    ): JsonResponse {
        try {
            $deleteProduct->handle($product);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to delete product.',
                'PRODUCT_DELETE_FAILED',
                500,
            );
        }

        return ApiResponse::success('Product deleted successfully.');
    }

    private function requestedStatus(Request $request): ProductStatus|null|false
    {
        if (! $request->filled('status')) {
            return null;
        }

        $status = ProductStatus::tryFrom((string) $request->query('status'));

        return $status ?? false;
    }

    private function requestedCategoryId(Request $request): int|null|false
    {
        if (! $request->filled('category_id')) {
            return null;
        }

        if (! is_numeric($request->query('category_id'))) {
            return false;
        }

        return (int) $request->query('category_id');
    }

    /**
     * @return bool|null|'invalid'
     */
    private function requestedFeatured(Request $request): bool|string|null
    {
        if (! $request->filled('featured')) {
            return null;
        }

        $value = filter_var($request->query('featured'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? 'invalid';
    }

    private function requestedSearch(Request $request): ?string
    {
        if (! $request->filled('search')) {
            return null;
        }

        return trim((string) $request->query('search'));
    }
}
