<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Enums\ServicePaymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'quotation_number',
    'customer_profile_id',
    'booking_id',
    'status',
    'currency',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'total_amount',
    'payment_type',
    'deposit_percentage',
    'deposit_amount',
    'remaining_amount',
    'valid_until',
    'accepted_at',
    'notes',
])]
class Quotation extends Model
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
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return HasMany<QuotationStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(QuotationStatusHistory::class);
    }

    /**
     * @return HasMany<QuotationDiscussionMessage, $this>
     */
    public function discussionMessages(): HasMany
    {
        return $this->hasMany(QuotationDiscussionMessage::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'payment_type' => ServicePaymentType::class,
            'deposit_percentage' => 'integer',
            'deposit_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'valid_until' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
