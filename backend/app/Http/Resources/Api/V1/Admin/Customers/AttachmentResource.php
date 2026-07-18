<?php

namespace App\Http\Resources\Api\V1\Admin\Customers;

use App\Models\CustomerAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAttachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'file_type' => $this->file_type?->value,
            'file_size' => $this->file_size,
            'uploaded_by' => [
                'name' => $this->admin?->name,
                'role' => $this->admin?->role?->value,
            ],
            'uploaded_at' => $this->created_at?->toISOString(),
        ];
    }
}
