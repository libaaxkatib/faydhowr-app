<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_date' => $this->payment_date?->toDateString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'notes' => $this->notes,
            'paid_by' => $this->whenLoaded('paidBy', fn () => $this->paidBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
