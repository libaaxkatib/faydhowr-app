<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeHistoricalCompletionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage->value,
            'source' => $this->source,
            'source_reference' => $this->source_reference,
            'source_notes' => $this->source_notes,
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
