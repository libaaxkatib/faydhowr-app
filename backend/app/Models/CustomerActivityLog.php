<?php

namespace App\Models;

use App\Enums\Customer\ActivityType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'customer_profile_id',
    'event_type',
    'description',
    'subject_type',
    'subject_id',
    'metadata',
    'created_at',
])]
class CustomerActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => ActivityType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
