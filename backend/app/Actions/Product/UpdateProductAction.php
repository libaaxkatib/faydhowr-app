<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class UpdateProductAction
{
    /**
     * @param  array{
     *     category_id?: int,
     *     sku?: string,
     *     name?: string,
     *     slug?: string,
     *     description?: string|null,
     *     selling_price?: float|int|string,
     *     cost_price?: float|int|string,
     *     current_stock?: int,
     *     low_stock_threshold?: int,
     *     is_featured?: bool,
     *     status?: string|ProductStatus
     * }  $data
     */
    public function handle(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $product = Product::query()
                ->whereKey($product)
                ->lockForUpdate()
                ->firstOrFail();

            $product->fill($data);
            $product->save();

            return $product->load(['category', 'images']);
        });
    }
}
