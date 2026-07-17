<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportExportResource extends JsonResource
{
    /**
     * Export metadata only; internal storage configuration is never exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_id' => $this->report_id,
            'report_type' => $this->report_type->value,
            'format' => $this->format->value,
            'status' => $this->status->value,
            'requested_by' => $this->requested_by,
            'file_path' => $this->file_path,
            'created_at' => $this->created_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
        ];
    }
}
