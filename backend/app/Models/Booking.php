<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'booking_number',
    'customer_profile_id',
    'service_id',
    'service_mode_id',
    'status',
    'requested_date',
    'requested_time_window',
    'scheduled_start_at',
    'scheduled_end_at',
    'address_snapshot',
    'customer_notes',
    'cancelled_at',
    'cancellation_reason',
])]
class Booking extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<ServiceModeOption, $this>
     */
    public function serviceMode(): BelongsTo
    {
        return $this->belongsTo(ServiceModeOption::class);
    }

    /**
     * @return HasMany<BookingStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class);
    }

    /**
     * @return HasMany<BookingMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(BookingMedia::class);
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * @return HasOne<Review, $this>
     */
    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'requested_date' => 'date',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'address_snapshot' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }
}
