<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'payment_number',
    'receipt_number',
    'customer_profile_id',
    'payable_type',
    'payable_id',
    'status',
    'amount',
    'currency',
    'gateway',
    'gateway_reference',
    'paid_at',
])]
class Payment extends Model
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
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * @return HasMany<PaymentStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(PaymentStatusHistory::class);
    }

    /**
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PaymentStatus::Pending,
            PaymentStatus::Initialized,
            PaymentStatus::Processing,
        ]);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }
}
