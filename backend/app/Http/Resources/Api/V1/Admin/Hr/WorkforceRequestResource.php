<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkforceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $matchedCount = $this->whenLoaded('matches', fn () => $this->matches->count(), 0);

        return [
            'id' => $this->id,
            'work_location_id' => $this->work_location_id,
            'work_location_name' => $this->whenLoaded('workLocation', fn () => $this->workLocation?->name),
            'client_company_name' => $this->whenLoaded(
                'workLocation',
                fn () => $this->workLocation?->clientCompany?->name,
            ),
            'employee_category_id' => $this->employee_category_id,
            'employee_category_name' => $this->whenLoaded('employeeCategory', fn () => $this->employeeCategory?->name),
            'position_id' => $this->position_id,
            'position_name' => $this->whenLoaded('position', fn () => $this->position?->name),
            'gender_requirement' => $this->gender_requirement?->value,
            'quantity_needed' => $this->quantity_needed,
            'matched_count' => $matchedCount,
            'status' => $this->status->value,
            'requested_date' => $this->requested_date?->toDateString(),
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'matches' => WorkforceRequestMatchResource::collection($this->whenLoaded('matches')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
