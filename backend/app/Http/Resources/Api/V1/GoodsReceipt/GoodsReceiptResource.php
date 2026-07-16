<?php

namespace App\Http\Resources\Api\V1\GoodsReceipt;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoodsReceiptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'gr_number' => $this->gr_number,
            'notes' => $this->notes,
            'received_at' => $this->received_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'supplier' => $this->whenLoaded('supplier', fn (): array => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'status' => $this->supplier->status->value,
            ]),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn (): array => [
                'id' => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
                'status' => $this->purchaseOrder->status->value,
            ]),
            'status_summary' => $this->whenLoaded('purchaseOrder', fn (): array => [
                'purchase_order_status' => $this->purchaseOrder->status->value,
                'received_at' => $this->received_at?->toISOString(),
            ]),
            'items' => $this->whenLoaded(
                'items',
                fn () => GoodsReceiptItemResource::collection($this->items)->resolve(),
            ),
        ];
    }
}
