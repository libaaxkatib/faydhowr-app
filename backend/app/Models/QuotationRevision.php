<?php

namespace App\Models;

use App\Enums\QuotationRevisionSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable versioned pricing revision (Sprint 28). Rows are never updated
 * or deleted; correcting pricing means issuing the next version.
 */
#[Fillable([
    'quotation_id',
    'version_number',
    'source',
    'subtotal_amount',
    'discount_amount',
    'tax_amount',
    'total_amount',
    'valid_until',
    'terms',
    'notes',
    'issued_by_admin_id',
])]
class QuotationRevision extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function issuedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'issued_by_admin_id');
    }

    public function isWithinValidity(): bool
    {
        return ! $this->valid_until->isPast();
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'source' => QuotationRevisionSource::class,
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'valid_until' => 'datetime',
        ];
    }
}
