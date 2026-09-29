<?php

namespace App\Http\Resources\Api\V1\Admin\Reconciliation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataIssueAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admin_name' => $this->whenLoaded('admin', fn () => $this->admin?->full_name),
            'field_changed' => $this->field_changed,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
