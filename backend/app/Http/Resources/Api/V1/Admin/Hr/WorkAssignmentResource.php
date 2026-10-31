<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Support\Hr\PositionSuccession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'work_location_id' => $this->work_location_id,
            'client_company_id' => $this->client_company_id,
            'work_location_name' => $this->whenLoaded('workLocation', fn () => $this->workLocation?->name),
            'location_type' => $this->when(
                $this->relationLoaded('workLocation'),
                fn () => $this->workLocation?->location_type->value ?? 'client',
            ),
            'client_company_name' => $this->when(
                $this->relationLoaded('clientCompany') || $this->relationLoaded('workLocation'),
                fn () => $this->clientCompany?->name ?? $this->workLocation?->clientCompany?->name,
            ),
            'replaces_assignment_id' => $this->replaces_assignment_id,
            // Only where the caller loaded BOTH the replaced assignment and its employee (the employee detail), so no other response changes and nothing is
            // queried here. The identity is exactly what the recorded link points at, never inferred; with no link the key is absent and
            // `replaces_assignment_id` is null.
            'replaces' => $this->when(
                $this->relationLoaded('replaces') && $this->replaces !== null && $this->replaces->relationLoaded('employee'),
                fn () => [
                    'assignment_id' => $this->replaces->id,
                    'employee_id' => $this->replaces->employee_id,
                    'employee_number' => $this->replaces->employee?->employee_number,
                    'full_name' => $this->replaces->employee?->full_name,
                ],
            ),
            'succession_gap_days' => $this->when(
                $this->relationLoaded('replaces') && $this->replaces?->end_date !== null,
                fn () => PositionSuccession::gapDays($this->replaces->end_date, $this->start_date),
            ),
            'assignment_type' => $this->assignment_type?->value ?? 'permanent',
            'shift' => $this->shift?->value,
            'position_id' => $this->position_id,
            'position_name' => $this->whenLoaded('position', fn () => $this->position?->name),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'salary_amount' => $this->salary_amount,
            'salary_currency' => $this->salary_currency,
            'salary_frequency' => $this->salary_frequency->value,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
