<?php

namespace App\Models;

use App\Enums\Upload\UploadMediaType;
use Database\Factories\UploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid',
    'customer_profile_id',
    'disk',
    'path',
    'original_name',
    'media_type',
    'mime_type',
    'file_size_bytes',
    'attached_at',
    'expires_at',
])]
class Upload extends Model
{
    /** @use HasFactory<UploadFactory> */
    use HasFactory;

    public function isAttached(): bool
    {
        return $this->attached_at !== null;
    }

    /**
     * Staging expiry applies only while unattached (API Design §14.8).
     */
    public function isExpired(): bool
    {
        return ! $this->isAttached()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_type' => UploadMediaType::class,
            'file_size_bytes' => 'integer',
            'attached_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
