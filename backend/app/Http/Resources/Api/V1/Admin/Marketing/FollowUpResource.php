<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'marketing_record_id' => $this->marketing_record_id,
            'record_number' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->record_number),
            'record_type' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->type?->value),
            'record_team_name' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->assignedTeam?->name),
            'follow_up_date' => $this->follow_up_date?->toDateString(),
            'status' => $this->status->value,
            'assigned_admin' => $this->whenLoaded('assignedAdmin', fn () => $this->assignedAdmin?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
            'histories' => FollowUpHistoryResource::collection($this->whenLoaded('histories')),
        ];
    }
}
