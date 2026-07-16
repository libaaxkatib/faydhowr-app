<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Models\Product;

class GetProductAction
{
    public function handle(int $productId, bool $canViewInactive): ?Product
    {
        $product = Product::query()
            ->with(['category', 'images'])
            ->whereKey($productId)
            ->first();

        if ($product === null) {
            return null;
        }

        if (! $canViewInactive && $product->status !== ProductStatus::Active) {
            return null;
        }

        return $product;
    }
}
