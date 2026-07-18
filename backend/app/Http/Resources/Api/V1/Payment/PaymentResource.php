<?php

namespace App\Http\Resources\Api\V1\Payment;

use App\Models\Order;
use App\Models\StoreOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'payment_number' => $this->payment_number,
            'receipt_number' => $this->receipt_number,
            'payable' => $this->whenLoaded('payable', function (): array {
                $payable = $this->payable;

                return [
                    'type' => match (true) {
                        $payable instanceof Order => 'order',
                        $payable instanceof StoreOrder => 'store_order',
                        default => class_basename($payable),
                    },
                    'order_number' => $payable instanceof Order ? $payable->order_number : null,
                    'store_order_number' => $payable instanceof StoreOrder ? $payable->store_order_number : null,
                ];
            }),
            'status' => $this->status->value,
            'payment_method' => $this->payment_method?->value,
            'payment_stage' => $this->payment_stage?->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'gateway' => $this->gateway,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
