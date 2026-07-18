<?php

namespace App\Http\Resources\Api\V1\Admin\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminFaqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'question' => $this->resource->question,
            'answer' => $this->resource->answer,
            'sort_order' => $this->resource->sort_order,
            'is_active' => $this->resource->is_active,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
