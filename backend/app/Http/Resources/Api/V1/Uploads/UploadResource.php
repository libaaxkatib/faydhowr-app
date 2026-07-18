<?php

namespace App\Http\Resources\Api\V1\Uploads;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UploadResource extends JsonResource
{
    /**
     * Public metadata shape (API Design §14.4). Numeric IDs, disks, and
     * storage paths are never exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->resource->uuid,
            'file_name' => $this->resource->original_name,
            'mime_type' => $this->resource->mime_type,
            'media_type' => $this->resource->media_type->value,
            'file_size_bytes' => $this->resource->file_size_bytes,
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
