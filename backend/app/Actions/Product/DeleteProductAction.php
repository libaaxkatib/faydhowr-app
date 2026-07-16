<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DeleteProductAction
{
    public function handle(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product = Product::query()
                ->whereKey($product)
                ->lockForUpdate()
                ->firstOrFail();

            $product->delete();
        });
    }
}
