<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'status' => $this->status->value,
            'client_company_id' => $this->client_company_id,
            'client_company_name' => $this->whenLoaded('clientCompany', fn () => $this->clientCompany?->name),
            'notes' => $this->notes,
            'recorded_by' => $this->whenLoaded('recordedBy', fn () => $this->recordedBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
