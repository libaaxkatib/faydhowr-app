<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

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
            'work_location_name' => $this->whenLoaded('workLocation', fn () => $this->workLocation?->name),
            'location_type' => $this->whenLoaded('workLocation', fn () => $this->workLocation?->location_type->value),
            'client_company_name' => $this->whenLoaded(
                'workLocation',
                fn () => $this->workLocation?->clientCompany?->name,
            ),
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
