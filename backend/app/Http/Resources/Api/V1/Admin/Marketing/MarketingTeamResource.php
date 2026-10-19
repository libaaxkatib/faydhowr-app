<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use App\Models\Admin;
use App\Support\Marketing\MarketingScope;
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
            // Whether the caller may add, create or remove this team's members (server-enforced; the panel only mirrors it).
            'can_manage_members' => $request->user() instanceof Admin && MarketingScope::canManageTeam($request->user(), (int) $this->id),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($admin) => [
                'id' => $admin->id,
                'full_name' => $admin->full_name,
            ])),
        ];
    }
}
