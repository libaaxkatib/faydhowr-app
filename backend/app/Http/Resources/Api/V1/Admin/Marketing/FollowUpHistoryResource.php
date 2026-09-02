<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'note' => $this->note,
            'performed_by' => $this->whenLoaded('performedBy', fn () => $this->performedBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
