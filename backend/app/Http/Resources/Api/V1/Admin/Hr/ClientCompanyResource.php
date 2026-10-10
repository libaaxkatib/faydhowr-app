<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'location' => $this->location,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'aliases' => $this->whenLoaded('aliases', fn () => $this->aliases->map(fn ($alias) => [
                'alias' => $alias->alias,
                'source' => $alias->source,
                'source_reference' => $alias->source_reference,
            ])->all()),
            'agreements_count' => $this->whenCounted('agreements'),
            'work_locations' => WorkLocationResource::collection($this->whenLoaded('workLocations')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
