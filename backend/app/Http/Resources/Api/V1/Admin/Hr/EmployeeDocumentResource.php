<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee_document_category_id' => $this->employee_document_category_id,
            'category_name' => $this->whenLoaded('category', fn () => $this->category?->name),
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'document_number' => $this->document_number,
            'verification_status' => $this->verification_status->value,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verified_by' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->full_name),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_current' => $this->is_current,
            'superseded_by_document_id' => $this->superseded_by_document_id,
            'uploaded_by' => $this->whenLoaded('admin', fn () => $this->admin?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
