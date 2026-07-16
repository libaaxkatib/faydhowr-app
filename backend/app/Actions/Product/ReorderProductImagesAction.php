<?php

namespace App\Actions\Product;

use App\Models\Product;
use App\Models\ProductImage;
use DomainException;
use Illuminate\Support\Facades\DB;

class ReorderProductImagesAction
{
    /**
     * @param  list<array{id: int, sort_order: int}>  $orderedImages
     * @return list<ProductImage>
     */
    public function handle(Product $product, array $orderedImages): array
    {
        return DB::transaction(function () use ($product, $orderedImages): array {
            $product = Product::query()
                ->whereKey($product)
                ->lockForUpdate()
                ->firstOrFail();

            $existingImages = ProductImage::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($existingImages->count() !== count($orderedImages)) {
                throw new DomainException('The reorder payload must include every product image exactly once.');
            }

            $ids = array_column($orderedImages, 'id');
            $sortOrders = array_column($orderedImages, 'sort_order');

            if (count($ids) !== count(array_unique($ids))) {
                throw new DomainException('The reorder payload contains duplicate image ids.');
            }

            if (count($sortOrders) !== count(array_unique($sortOrders))) {
                throw new DomainException('Image sort orders must be unique within the product.');
            }

            foreach ($orderedImages as $item) {
                if (! $existingImages->has($item['id'])) {
                    throw new DomainException('One or more images do not belong to this product.');
                }
            }

            foreach ($existingImages->values() as $index => $image) {
                $image->update(['sort_order' => 1_000_000 + $index]);
            }

            foreach ($orderedImages as $item) {
                /** @var ProductImage $image */
                $image = $existingImages->get($item['id']);
                $image->update(['sort_order' => $item['sort_order']]);
            }

            return ProductImage::query()
                ->where('product_id', $product->id)
                ->orderBy('sort_order')
                ->get()
                ->all();
        });
    }
}
