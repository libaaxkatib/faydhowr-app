<?php

namespace App\Http\Resources\Api\V1\Cart;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->relationLoaded('items') ? $this->items : collect();

        $totalQuantity = (int) $items->sum('quantity');
        $subtotal = $items->reduce(
            function (string $carry, $item): string {
                $unitPrice = (string) $item->product->selling_price;

                return bcadd($carry, bcmul($unitPrice, (string) $item->quantity, 2), 2);
            },
            '0.00',
        );

        return [
            'items' => CartItemResource::collection($items),
            'total_items' => $items->count(),
            'total_quantity' => $totalQuantity,
            'subtotal' => $subtotal,
        ];
    }
}
