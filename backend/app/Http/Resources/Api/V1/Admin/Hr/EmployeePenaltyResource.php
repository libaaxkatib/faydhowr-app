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
            'client_company_id' => $this->client_company_id,
            'client_company_name' => $this->whenLoaded('clientCompany', fn () => $this->clientCompany?->name),
            'notes' => $this->notes,
            // Release 1B record integrity: status ('active' counts, 'voided' is history only), the version a correction must quote, the chain, and the balance.
            'status' => $this->status,
            'version' => $this->version,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'replaces_id' => $this->replaces_id,
            'waived_cents' => (int) $this->waived_cents,
            'waive_reason' => $this->waive_reason,
            'deducted_cents' => $this->when($this->resource->offsetExists('deducted_cents'), fn () => (int) $this->getAttribute('deducted_cents')),
            'owed_cents' => $this->when($this->resource->offsetExists('owed_cents'), fn () => (int) $this->getAttribute('owed_cents')),
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
