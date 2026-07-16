<?php

namespace App\Http\Resources\Api\V1\PurchaseOrder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'po_number' => $this->po_number,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'supplier' => $this->whenLoaded('supplier', fn (): array => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'status' => $this->supplier->status->value,
            ]),
            'items' => $this->whenLoaded(
                'items',
                fn () => PurchaseOrderItemResource::collection($this->items)->resolve(),
            ),
        ];
    }
}
