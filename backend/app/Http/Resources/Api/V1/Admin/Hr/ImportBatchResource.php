<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Models\ImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ImportBatch */
class ImportBatchResource extends JsonResource
{
    /** @var array<string, mixed>|null */
    private ?array $extra = null;

    /**
     * @param  array<string, mixed>  $extra  additional fields (summary, unrecognised headers)
     */
    public function withExtra(array $extra): static
    {
        $this->extra = $extra;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'preview_only' => $this->type->isPreviewOnly(),
            'status' => $this->status->value,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy?->full_name),
            'source_extension' => $this->source_extension,
            'source_size' => $this->source_size,
            'counts' => [
                'total' => $this->total_rows,
                'valid' => $this->valid_rows,
                'invalid' => $this->invalid_rows,
                'duplicate' => $this->duplicate_rows,
                'review' => $this->review_rows,
                'imported' => $this->imported_rows,
                'skipped' => $this->skipped_rows,
                'failed' => $this->failed_rows,
            ],
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'retention_expires_at' => $this->retention_expires_at?->toIso8601String(),
            'details_deleted' => $this->pii_purged_at !== null,
            'details_deleted_at' => $this->pii_purged_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            ...($this->extra ?? []),
        ];
    }
}
