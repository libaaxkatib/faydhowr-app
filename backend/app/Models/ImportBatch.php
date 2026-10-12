<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type', 'status', 'created_by', 'source_sha256', 'source_size', 'source_extension',
    'total_rows', 'valid_rows', 'invalid_rows', 'duplicate_rows', 'review_rows',
    'imported_rows', 'skipped_rows', 'failed_rows',
    'confirmed_by', 'confirmed_at', 'completed_at', 'lease_token', 'lease_expires_at',
    'retention_expires_at', 'pii_purged_at',
])]
class ImportBatch extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportBatchStatus::class,
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'retention_expires_at' => 'datetime',
            'pii_purged_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'confirmed_by');
    }

    public function isPurged(): bool
    {
        return $this->pii_purged_at !== null;
    }
}
