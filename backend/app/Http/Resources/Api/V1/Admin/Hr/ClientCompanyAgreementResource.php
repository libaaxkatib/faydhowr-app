<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientCompanyAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_company_id' => $this->client_company_id,
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'notes' => $this->notes,
            'uploaded_by' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->full_name),
            'uploaded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
