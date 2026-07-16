<?php

namespace App\Http\Resources\Api\V1\Product;

use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, ProductImage> $images */
        $images = $this->relationLoaded('images')
            ? $this->images
            : collect();

        $primaryImage = $images->firstWhere('is_primary', true);
        $additionalImages = $images->where('is_primary', false)->values();

        if ($primaryImage === null && $images->isNotEmpty()) {
            $primaryImage = $images->first();
            $additionalImages = $images->slice(1)->values();
        }

        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'selling_price' => $this->selling_price,
            'currency' => $this->currency,
            'current_stock' => $this->current_stock,
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_featured' => $this->is_featured,
            'status' => $this->status->value,
            'category' => $this->whenLoaded('category', fn (): array => [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'primary_image' => $primaryImage === null
                ? null
                : (new ProductImageResource($primaryImage))->resolve(),
            'additional_images' => ProductImageResource::collection($additionalImages)->resolve(),
        ];
    }
}
