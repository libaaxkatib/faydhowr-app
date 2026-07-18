<?php

namespace App\Http\Resources\Api\V1\Reviews;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-facing shape: includes status and booking/service context so the
 * customer can track moderation state (API Design §8A.2).
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'rating' => $this->resource->rating,
            'title' => $this->resource->title,
            'comment' => $this->resource->comment,
            'status' => $this->resource->status->value,
            'booking_number' => $this->whenLoaded('booking', fn () => $this->resource->booking->booking_number),
            'service' => $this->whenLoaded('service', fn (): array => [
                'name' => $this->resource->service->name,
                'slug' => $this->resource->service->slug,
            ]),
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
