<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteProductImageAction
{
    public function handle(Product $product, ProductImage $image): void
    {
        DB::transaction(function () use ($product, $image): void {
            $image = ProductImage::query()
                ->whereKey($image)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $wasPrimary = $image->is_primary;
            $path = $image->image_path;
            $disk = (string) config('products.images.disk');

            $image->delete();

            if ($wasPrimary) {
                $nextPrimary = ProductImage::query()
                    ->where('product_id', $product->id)
                    ->orderBy('sort_order')
                    ->lockForUpdate()
                    ->first();

                if ($nextPrimary !== null) {
                    $nextPrimary->update(['is_primary' => true]);
                }
            }

            Storage::disk($disk)->delete($path);
        });
    }
}
