<?php

namespace App\Http\Resources\Api\V1\Admin\Reviews;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminReviewResource extends JsonResource
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
            'customer' => $this->whenLoaded('customerProfile', fn (): array => [
                'id' => $this->resource->customerProfile->id,
                'customer_number' => $this->resource->customerProfile->customer_number,
                'full_name' => $this->resource->customerProfile->full_name,
            ]),
            'booking' => $this->whenLoaded('booking', fn (): array => [
                'id' => $this->resource->booking->id,
                'booking_number' => $this->resource->booking->booking_number,
                'status' => $this->resource->booking->status->value,
            ]),
            'service' => $this->whenLoaded('service', fn (): array => [
                'id' => $this->resource->service->id,
                'name' => $this->resource->service->name,
                'slug' => $this->resource->service->slug,
            ]),
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
