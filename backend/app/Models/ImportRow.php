<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'import_batch_id', 'row_number', 'status', 'code', 'message',
    'payload', 'name_key', 'phone_key', 'meta', 'entity_type', 'entity_id', 'processed_at',
])]
class ImportRow extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ImportRowStatus::class,
            'payload' => 'array',
            'meta' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
