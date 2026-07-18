<?php

namespace App\Models;

use Database\Factories\ServiceMediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_id',
    'media_type',
    'url',
    'alt_text',
    'sort_order',
    'is_primary',
])]
class ServiceMedia extends Model
{
    /** @use HasFactory<ServiceMediaFactory> */
    use HasFactory;

    protected $table = 'service_media';

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_primary' => 'boolean',
        ];
    }
}
