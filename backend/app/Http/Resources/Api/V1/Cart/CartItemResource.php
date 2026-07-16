<?php

namespace App\Http\Resources\Api\V1\Cart;

use App\Http\Resources\Api\V1\Product\ProductImageResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->product;
        $unitPrice = $product->selling_price;
        $lineTotal = bcmul((string) $unitPrice, (string) $this->quantity, 2);

        $primaryImage = $product->relationLoaded('images')
            ? ($product->images->firstWhere('is_primary', true) ?? $product->images->first())
            : null;

        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'product' => [
                'sku' => $product->sku,
                'name' => $product->name,
                'slug' => $product->slug,
                'selling_price' => $product->selling_price,
                'currency' => $product->currency,
                'current_stock' => $product->current_stock,
                'status' => $product->status->value,
                'primary_image' => $primaryImage === null
                    ? null
                    : (new ProductImageResource($primaryImage))->resolve(),
            ],
        ];
    }
}
