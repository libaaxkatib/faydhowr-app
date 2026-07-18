<?php

namespace App\Http\Resources\Api\V1\Admin\StoreOrders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminStoreOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_order_number' => $this->store_order_number,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'total_items' => $this->total_items,
            'total_quantity' => $this->total_quantity,
            'subtotal' => $this->subtotal,
            'shipping_address_snapshot' => $this->shipping_address_snapshot,
            'notes' => $this->notes,
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at?->toISOString(),
            'customer' => $this->whenLoaded('customerProfile', fn (): array => [
                'id' => $this->customerProfile->id,
                'customer_number' => $this->customerProfile->customer_number,
                'full_name' => $this->customerProfile->full_name,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(
                fn ($item): array => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name_snapshot,
                    'sku' => $item->sku_snapshot,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price_snapshot,
                    'line_total' => $item->line_total_snapshot,
                ],
            )->all()),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(
                fn ($payment): array => [
                    'id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'status' => $payment->status->value,
                    'payment_method' => $payment->payment_method?->value,
                    'payment_stage' => $payment->payment_stage?->value,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'paid_at' => $payment->paid_at?->toISOString(),
                ],
            )->all()),
            'status_histories' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories->map(
                fn ($history): array => [
                    'status' => $history->status->value,
                    'changed_by_type' => $history->changed_by_type,
                    'changed_by_id' => $history->changed_by_id,
                    'notes' => $history->notes,
                    'created_at' => $history->created_at?->toISOString(),
                ],
            )->all()),
        ];
    }
}
