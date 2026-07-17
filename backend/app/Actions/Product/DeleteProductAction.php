<?php

namespace App\Actions\Product;

use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DeleteProductAction
{
    public function __construct(private DashboardCacheInvalidatorInterface $dashboardCache) {}

    public function handle(Product $product): void
    {
        DB::transaction(function () use ($product): void {
            $product = Product::query()
                ->whereKey($product)
                ->lockForUpdate()
                ->firstOrFail();

            $product->delete();
        });

        $this->dashboardCache->invalidate();
    }
}
