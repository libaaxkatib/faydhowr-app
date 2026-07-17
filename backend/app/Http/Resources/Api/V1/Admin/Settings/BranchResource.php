<?php

namespace App\Http\Resources\Api\V1\Admin\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'city' => $this->city,
            'status' => $this->status->value,
            'is_default' => $this->is_default,
            'activated_at' => $this->activated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
