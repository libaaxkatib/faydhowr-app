<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLeaveResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'leave_type' => $this->leave_type->value,
            'leave_type_label' => $this->leave_type->label(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->full_name),
            'employee_id' => $this->whenLoaded('employee', fn () => $this->employee?->id),
            'employee_name' => $this->whenLoaded('employee', fn () => $this->employee?->full_name),
            'department_name' => $this->whenLoaded('employee', fn () => $this->employee?->department?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
