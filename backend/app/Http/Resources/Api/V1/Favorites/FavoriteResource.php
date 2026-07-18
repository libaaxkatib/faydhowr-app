<?php

namespace App\Http\Resources\Api\V1\Favorites;

use App\Http\Resources\Api\V1\Catalog\ServiceCardResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-specific favorites payload (API Design §12.3): the full Service
 * Card enriched with is_favorite — the only surface where the flag appears.
 */
class FavoriteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $card = (new ServiceCardResource($this->resource->service))->toArray($request);

        return array_merge($card, [
            'is_favorite' => true,
        ]);
    }
}
