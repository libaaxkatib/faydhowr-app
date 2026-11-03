<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One company's part of a monthly payroll run, with its own lifecycle (draft -> reviewed -> approved -> finalized), its own reviewer / approver /
 * finalizer and its own totals. client_company_id null = the lines whose assignment names no company.
 */
#[Fillable([
    'payroll_run_id', 'client_company_id', 'status', 'reviewed_at', 'reviewed_by', 'approved_at', 'approved_by', 'finalized_at', 'finalized_by', 'totals',
])]
class PayrollRunSection extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_FINALIZED = 'finalized';

    /** Least advanced first: a run's own status is the least advanced of its sections. */
    public const ORDER = [self::STATUS_DRAFT, self::STATUS_REVIEWED, self::STATUS_APPROVED, self::STATUS_FINALIZED];

    /** Approved and finalized sections are protected: their lines are never regenerated and their inputs are locked. */
    public const LOCKED_STATUSES = [self::STATUS_APPROVED, self::STATUS_FINALIZED];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalized_at' => 'datetime',
            'totals' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class, 'section_id');
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

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }
}
