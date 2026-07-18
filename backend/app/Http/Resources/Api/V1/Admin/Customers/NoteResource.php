<?php

namespace App\Http\Resources\Api\V1\Admin\Customers;

use App\Models\CustomerNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerNote
 */
class NoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note' => $this->body,
            'created_by' => [
                'name' => $this->admin?->name,
                'role' => $this->admin?->role?->value,
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
