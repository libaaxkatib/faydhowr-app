<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class SetPrimaryProductImageAction
{
    public function handle(Product $product, ProductImage $image): ProductImage
    {
        return DB::transaction(function () use ($product, $image): ProductImage {
            $image = ProductImage::query()
                ->whereKey($image)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($image === null) {
                throw new ModelNotFoundException;
            }

            ProductImage::query()
                ->where('product_id', $product->id)
                ->where('is_primary', true)
                ->whereKeyNot($image->id)
                ->lockForUpdate()
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);

            return $image->refresh();
        });
    }
}
