<?php

namespace App\Http\Resources\Api\V1\Catalog;

use App\Models\ServiceModeOption;
use App\Support\Catalog\ServiceImages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->resource->slug,
            'name' => $this->resource->name,
            'short_description' => $this->resource->short_description,
            'starting_from_price' => $this->resource->starting_from_price,
            'currency' => $this->resource->currency,
            'modes' => $this->resource->modes->map(fn (ServiceModeOption $option): array => [
                'mode' => $option->mode->value,
                'subtype' => $option->subtype?->value,
            ])->values(),
            'coverage_cities' => $this->resource->coverageCities->pluck('city')->values(),
            'images' => ServiceImages::for($this->resource),
        ];
    }
}
