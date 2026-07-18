<?php

namespace App\Http\Resources\Api\V1\Admin\Payments;

use App\Models\Order;
use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_number' => $this->payment_number,
            'receipt_number' => $this->receipt_number,
            'status' => $this->status->value,
            'payment_method' => $this->payment_method?->value,
            'payment_stage' => $this->payment_stage?->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'gateway' => $this->gateway,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'customer' => $this->whenLoaded('customerProfile', fn (): array => [
                'id' => $this->customerProfile->id,
                'customer_number' => $this->customerProfile->customer_number,
                'full_name' => $this->customerProfile->full_name,
            ]),
            'payable' => $this->whenLoaded('payable', function (): ?array {
                $payable = $this->payable;

                if ($payable === null) {
                    return null;
                }

                return [
                    'type' => match (true) {
                        $payable instanceof Order => 'order',
                        $payable instanceof StoreOrder => 'store_order',
                        default => class_basename($payable),
                    },
                    'id' => $payable->id,
                    'number' => match (true) {
                        $payable instanceof Order => $payable->order_number,
                        $payable instanceof StoreOrder => $payable->store_order_number,
                        default => null,
                    },
                    'status' => $payable->status?->value,
                ];
            }),
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
