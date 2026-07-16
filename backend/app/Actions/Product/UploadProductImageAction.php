<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class UploadProductImageAction
{
    public function handle(Product $product, UploadedFile $image): ProductImage
    {
        $disk = (string) config('products.images.disk');
        $directory = trim((string) config('products.images.directory'), '/').'/'.$product->id;

        return DB::transaction(function () use ($product, $image, $disk, $directory): ProductImage {
            $product = Product::query()
                ->whereKey($product)
                ->lockForUpdate()
                ->firstOrFail();

            $existingCount = $product->images()->lockForUpdate()->count();
            $nextSortOrder = (int) $product->images()->max('sort_order');
            $sortOrder = $existingCount === 0 ? 0 : $nextSortOrder + 1;

            $path = $image->store($directory, $disk);

            if ($path === false) {
                throw new RuntimeException('Failed to store product image.');
            }

            try {
                return ProductImage::query()->create([
                    'product_id' => $product->id,
                    'image_path' => $path,
                    'sort_order' => $sortOrder,
                    'is_primary' => $existingCount === 0,
                ]);
            } catch (\Throwable $exception) {
                Storage::disk($disk)->delete($path);

                throw $exception;
            }
        });
    }
}
