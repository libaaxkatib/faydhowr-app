<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Actions\Product\DeleteProductImageAction;
use App\Actions\Product\ReorderProductImagesAction;
use App\Actions\Product\SetPrimaryProductImageAction;
use App\Actions\Product\UploadProductImageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Product\ReorderProductImagesRequest;
use App\Http\Requests\Api\V1\Product\UploadProductImageRequest;
use App\Http\Resources\Api\V1\Product\ProductImageResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Throwable;

class ProductImageController extends Controller
{
    public function store(
        UploadProductImageRequest $request,
        Product $product,
        UploadProductImageAction $uploadProductImage,
    ): JsonResponse {
        /** @var UploadedFile $image */
        $image = $request->file('image');

        try {
            $productImage = $uploadProductImage->handle($product, $image);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to upload product image.',
                'PRODUCT_IMAGE_UPLOAD_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Product image uploaded successfully.',
            new ProductImageResource($productImage),
            201,
        );
    }

    public function destroy(
        Product $product,
        int $image,
        DeleteProductImageAction $deleteProductImage,
    ): JsonResponse {
        try {
            $productImage = $this->resolveProductImage($product, $image);
            $deleteProductImage->handle($product, $productImage);
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Product image not found.',
                'PRODUCT_IMAGE_NOT_FOUND',
                404,
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to delete product image.',
                'PRODUCT_IMAGE_DELETE_FAILED',
                500,
            );
        }

        return ApiResponse::success('Product image deleted successfully.');
    }

    public function primary(
        Product $product,
        int $image,
        SetPrimaryProductImageAction $setPrimaryProductImage,
    ): JsonResponse {
        try {
            $productImage = $this->resolveProductImage($product, $image);
            $updatedImage = $setPrimaryProductImage->handle($product, $productImage);
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Product image not found.',
                'PRODUCT_IMAGE_NOT_FOUND',
                404,
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to set primary product image.',
                'PRODUCT_IMAGE_PRIMARY_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Primary product image updated successfully.',
            new ProductImageResource($updatedImage),
        );
    }

    public function reorder(
        ReorderProductImagesRequest $request,
        Product $product,
        ReorderProductImagesAction $reorderProductImages,
    ): JsonResponse {
        try {
            /** @var list<array{id: int, sort_order: int}> $orderedImages */
            $orderedImages = $request->validated('images');
            $images = $reorderProductImages->handle($product, $orderedImages);
        } catch (DomainException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                'VALIDATION_ERROR',
                422,
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to reorder product images.',
                'PRODUCT_IMAGE_REORDER_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Product images reordered successfully.',
            ProductImageResource::collection($images),
        );
    }

    private function resolveProductImage(Product $product, int $imageId): ProductImage
    {
        return ProductImage::query()
            ->whereKey($imageId)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }
}
