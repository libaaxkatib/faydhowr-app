<?php

namespace App\Http\Resources\Api\V1\Quotation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Attachment metadata is read from the referenced upload row; storage paths
 * are never exposed (Database Design §3.5.2).
 */
class QuotationAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->upload?->uuid,
            'original_name' => $this->upload?->original_name,
            'media_type' => $this->upload?->media_type?->value,
            'mime_type' => $this->upload?->mime_type,
            'file_size_bytes' => $this->upload?->file_size_bytes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
