<?php

namespace App\Http\Resources\Api\V1\Admin\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_number' => $this->entry_number,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'description' => $this->description,
            'entry_date' => $this->entry_date?->toDateString(),
            'status' => $this->status->value,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'lines' => JournalEntryLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
