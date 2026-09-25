<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemporaryReplacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totalPaid = $this->whenLoaded('payments', fn () => number_format((float) $this->payments->sum('amount'), 2, '.', ''), '0.00');

        return [
            'id' => $this->id,
            'work_assignment_id' => $this->work_assignment_id,
            'replaced_employee_id' => $this->whenLoaded('workAssignment', fn () => $this->workAssignment?->employee_id),
            'replaced_employee_name' => $this->whenLoaded('workAssignment', fn () => $this->workAssignment?->employee?->full_name),
            'work_location_name' => $this->whenLoaded('workAssignment', fn () => $this->workAssignment?->workLocation?->name),
            'client_company_name' => $this->whenLoaded(
                'workAssignment',
                fn () => $this->workAssignment?->workLocation?->clientCompany?->name,
            ),
            'replacement_employee_id' => $this->replacement_employee_id,
            'replacement_employee_name' => $this->whenLoaded('replacementEmployee', fn () => $this->replacementEmployee?->full_name),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'daily_rate' => $this->daily_rate,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'payments' => TemporaryReplacementPaymentResource::collection($this->whenLoaded('payments')),
            'total_paid' => $totalPaid,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
