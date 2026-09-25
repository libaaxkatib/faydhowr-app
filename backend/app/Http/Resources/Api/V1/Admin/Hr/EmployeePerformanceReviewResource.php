<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeePerformanceReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'review_date' => $this->review_date?->toDateString(),
            'rating' => $this->rating->value,
            'rating_label' => $this->rating->label(),
            'notes' => $this->notes,
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->full_name),
            'employee_id' => $this->whenLoaded('employee', fn () => $this->employee?->id),
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'department_name' => $this->whenLoaded('employee', fn () => $this->employee?->department?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
