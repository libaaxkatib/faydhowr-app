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
            // The record's saved status and feedback, so the follow-up screen starts its "Update status" / "Add feedback" editors from what is saved.
            'record_status' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->status?->value),
            'record_feedback' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->feedback),
            'record_team_name' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->assignedTeam?->name),
            'phone' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->xarunDetail?->phone ?? $this->marketingRecord?->projectDetail?->phone),
            'location' => $this->whenLoaded('marketingRecord', fn () => $this->marketingRecord?->xarunDetail?->location ?? $this->marketingRecord?->projectDetail?->location),
            'follow_up_date' => $this->follow_up_date?->toDateString(),
            'status' => $this->status->value,
            'assigned_admin' => $this->whenLoaded('assignedAdmin', fn () => $this->assignedAdmin?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
            'histories' => FollowUpHistoryResource::collection($this->whenLoaded('histories')),
        ];
    }
}
