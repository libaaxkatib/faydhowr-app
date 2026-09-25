<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'contract_type' => $this->contract_type,
            'contract_number' => $this->contract_number,
            'date_issued' => $this->date_issued?->toDateString(),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status->value,
            'signed_date' => $this->signed_date?->toDateString(),
            'signed_document_id' => $this->signed_document_id,
            'signed_document_name' => $this->whenLoaded('signedDocument', fn () => $this->signedDocument?->file_name),
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
