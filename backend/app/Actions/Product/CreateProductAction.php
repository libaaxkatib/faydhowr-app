<?php

namespace App\Actions\Product;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class CreateProductAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    /**
     * @param  array{
     *     category_id: int,
     *     sku: string,
     *     name: string,
     *     slug: string,
     *     description?: string|null,
     *     selling_price: float|int|string,
     *     cost_price: float|int|string,
     *     currency: string,
     *     current_stock: int,
     *     low_stock_threshold: int,
     *     is_featured?: bool,
     *     status?: string|ProductStatus|null
     * }  $data
     */
    public function handle(array $data): Product
    {
        $product = DB::transaction(function () use ($data): Product {
            $product = Product::query()->create([
                'category_id' => $data['category_id'],
                'sku' => $data['sku'],
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'selling_price' => $data['selling_price'],
                'cost_price' => $data['cost_price'],
                'currency' => $data['currency'],
                'current_stock' => $data['current_stock'],
                'low_stock_threshold' => $data['low_stock_threshold'],
                'is_featured' => $data['is_featured'] ?? false,
                'status' => $data['status'] ?? ProductStatus::Active,
            ]);

            return $product->load(['category', 'images']);
        });

        $this->dashboardCache->invalidate();

        return $product;
    }
}
