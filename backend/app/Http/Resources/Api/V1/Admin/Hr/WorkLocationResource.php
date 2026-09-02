<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activeCount = $this->active_assignments_count ?? $this->activeAssignmentsCount();

        return [
            'id' => $this->id,
            'client_company_id' => $this->client_company_id,
            'client_company_name' => $this->whenLoaded('clientCompany', fn () => $this->clientCompany?->name),
            'location_type' => $this->location_type->value,
            'name' => $this->name,
            'location' => $this->location,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'capacity' => $this->capacity,
            'active_assignments_count' => (int) $activeCount,
            'available_slots' => $this->capacity === null ? null : max(0, $this->capacity - $activeCount),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
