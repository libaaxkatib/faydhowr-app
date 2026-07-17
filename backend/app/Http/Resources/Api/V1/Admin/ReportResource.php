<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    /**
     * Report metadata only; the generated payload is never persisted.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_type' => $this->report_type->value,
            'format' => $this->format->value,
            'filters' => $this->filters,
            'generated_by' => $this->generated_by,
            'generated_at' => $this->generated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
