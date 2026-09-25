<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketingEmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'role' => $this->role->value,
            'teams' => $this->whenLoaded('marketingTeams', fn () => $this->marketingTeams->map(fn ($team) => [
                'id' => $team->id,
                'name' => $team->name,
            ])),
        ];
    }
}
