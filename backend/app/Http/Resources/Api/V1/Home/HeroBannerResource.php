<?php

namespace App\Http\Resources\Api\V1\Home;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public banner shape (API Design §5.3). Scheduling and activation fields
 * are admin-only; publicly visible banners are always active and inside
 * their schedule window.
 */
class HeroBannerResource extends JsonResource
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
        ];
    }
}
