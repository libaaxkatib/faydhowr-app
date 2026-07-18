<?php

namespace App\Models;

use App\Enums\Review\ReviewStatus;
use App\Support\Review\ReviewerName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_profile_id',
    'booking_id',
    'service_id',
    'rating',
    'title',
    'comment',
    'status',
])]
class Review extends Model
{
    use HasFactory;

    /**
     * Soft-deleted authors must remain resolvable for public payloads
     * ("Verified Customer" fallback), hence withTrashed().
     *
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isPending(): bool
    {
        return $this->status === ReviewStatus::Pending;
    }

    public function reviewerDisplayName(): string
    {
        return ReviewerName::for($this->customerProfile);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'status' => ReviewStatus::class,
        ];
    }
}
