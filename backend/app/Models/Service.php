<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'category_id',
    'name',
    'slug',
    'short_description',
    'description',
    'inclusions',
    'exclusions',
    'starting_from_price',
    'currency',
    'duration_minutes',
    'min_lead_hours',
    'max_concurrent_bookings',
    'requires_address',
    'is_active',
    'sort_order',
])]
class Service extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    /**
     * @return HasMany<ServiceModeOption, $this>
     */
    public function modes(): HasMany
    {
        return $this->hasMany(ServiceModeOption::class);
    }

    /**
     * @return HasMany<ServiceCoverageCity, $this>
     */
    public function coverageCities(): HasMany
    {
        return $this->hasMany(ServiceCoverageCity::class);
    }

    /**
     * @return HasMany<ServiceMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ServiceMedia::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starting_from_price' => 'decimal:2',
            'average_rating' => 'decimal:2',
            'reviews_count' => 'integer',
            'duration_minutes' => 'integer',
            'min_lead_hours' => 'integer',
            'max_concurrent_bookings' => 'integer',
            'requires_address' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
