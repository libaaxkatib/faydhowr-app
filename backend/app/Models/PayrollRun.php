<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'period', 'status', 'currency', 'generated_at', 'generated_by', 'reviewed_at', 'reviewed_by',
    'approved_at', 'approved_by', 'finalized_at', 'finalized_by', 'totals', 'notes',
])]
class PayrollRun extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_FINALIZED = 'finalized';

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'totals' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'generated_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'finalized_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REVIEWED], true);
    }
}
