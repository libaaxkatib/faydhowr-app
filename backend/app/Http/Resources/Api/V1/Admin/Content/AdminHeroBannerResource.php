<?php

namespace App\Http\Resources\Api\V1\Admin\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin banner shape (API Design §18.11): includes activation and schedule
 * state so inactive and out-of-schedule banners remain manageable.
 */
class AdminHeroBannerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'subtitle' => $this->resource->subtitle,
            'image_url' => $this->resource->image_url,
            'action_type' => $this->resource->action_type->value,
            'action_reference' => $this->resource->action_reference,
            'sort_order' => $this->resource->sort_order,
            'is_active' => $this->resource->is_active,
            'starts_at' => $this->resource->starts_at?->toISOString(),
            'ends_at' => $this->resource->ends_at?->toISOString(),
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
