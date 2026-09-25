<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePenaltyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'penalty_date' => $this->penalty_date?->toDateString(),
            'reason' => $this->reason,
            'deduction_amount' => $this->deduction_amount,
            'currency' => $this->currency,
            'payroll_period' => $this->payroll_period,
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
