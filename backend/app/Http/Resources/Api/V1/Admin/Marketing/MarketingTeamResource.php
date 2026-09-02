<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingTeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($admin) => [
                'id' => $admin->id,
                'full_name' => $admin->full_name,
            ])),
        ];
    }
}
