<?php

namespace App\Http\Resources\Api\V1\Home;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BeforeAfterItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'before_image_url' => $this->resource->before_image_url,
            'after_image_url' => $this->resource->after_image_url,
            'service' => $this->resource->service === null ? null : [
                'name' => $this->resource->service->name,
                'slug' => $this->resource->service->slug,
            ],
            'sort_order' => $this->resource->sort_order,
        ];
    }
}
