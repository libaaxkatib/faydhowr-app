<?php

namespace App\Http\Resources\Api\V1\Reviews;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape (API Design §8A.5): reviewer identity is First Name + Initial
 * ("Verified Customer" for soft-deleted authors); no IDs or PII are exposed.
 */
class PublicReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rating' => $this->resource->rating,
            'title' => $this->resource->title,
            'comment' => $this->resource->comment,
            'reviewer_name' => $this->resource->reviewerDisplayName(),
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
