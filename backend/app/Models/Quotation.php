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
    'requirements',
    'description',
    'preferred_timing',
    'quantity_hint',
    'assigned_admin_id',
    'latest_revision_id',
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
    'submitted_at',
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
     * @return BelongsTo<Admin, $this>
     */
    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_admin_id');
    }

    /**
     * @return BelongsTo<QuotationRevision, $this>
     */
    public function latestRevision(): BelongsTo
    {
        return $this->belongsTo(QuotationRevision::class, 'latest_revision_id');
    }

    /**
     * @return HasMany<QuotationRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(QuotationRevision::class);
    }

    /**
     * @return HasMany<QuotationAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(QuotationAttachment::class);
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
     * Server-calculated business flag (API Design §9.4A): acceptance is
     * permitted only from quotation_ready / under_discussion while the
     * latest revision (or legacy head validity) is within valid_until.
     * Clients must never re-derive this rule.
     */
    public function canAccept(): bool
    {
        if (! $this->canDiscuss()) {
            return false;
        }

        $validUntil = $this->latest_revision_id !== null
            ? $this->latestRevision?->valid_until
            : $this->valid_until;

        return $validUntil === null || ! $validUntil->isPast();
    }

    /**
     * Server-calculated business flag (API Design §9.4A): discussion is open
     * only while quotation_ready / under_discussion.
     */
    public function canDiscuss(): bool
    {
        return in_array($this->status, [
            QuotationStatus::QuotationReady,
            QuotationStatus::UnderDiscussion,
        ], true);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuotationStatus::class,
            'quantity_hint' => 'integer',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'payment_type' => ServicePaymentType::class,
            'deposit_percentage' => 'integer',
            'deposit_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'valid_until' => 'datetime',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
