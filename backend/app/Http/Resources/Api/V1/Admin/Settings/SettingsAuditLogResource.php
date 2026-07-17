<?php

namespace App\Http\Resources\Api\V1\Admin\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SettingsAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'key' => $this->category.'.'.$this->key,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'changed_by' => $this->whenLoaded('changedBy', fn (): array => [
                'name' => $this->changedBy->full_name,
                'role' => $this->changedBy->role->value,
            ]),
            'changed_at' => $this->changed_at?->toISOString(),
            'ip_address' => $this->ip_address,
        ];
    }
}
