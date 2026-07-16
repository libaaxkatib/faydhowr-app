<?php

namespace App\Http\Resources\Api\V1\StoreOrder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'store_order_number' => $this->store_order_number,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'total_items' => $this->total_items,
            'total_quantity' => $this->total_quantity,
            'subtotal' => $this->subtotal,
            'notes' => $this->notes,
            'shipping_address' => $this->shipping_address_snapshot,
            'items' => StoreOrderItemResource::collection($this->whenLoaded('items')),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
