<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'advance_date', 'amount', 'currency', 'payroll_period', 'reason', 'notes', 'recorded_by', 'client_company_id',
    'idempotency_key', 'status', 'version', 'voided_at', 'voided_by', 'void_reason', 'replaces_id', 'waived_cents', 'waived_at', 'waived_by', 'waive_reason', 'recorded_by_role',
])]
class EmployeeAdvance extends Model
{
    use HasFactory;

    /** Counts in payroll. */
    public const STATUS_ACTIVE = 'active';

    /** Cancelled before it was deducted (or replaced by a corrected row). Kept for the history; never counted. */
    public const STATUS_VOIDED = 'voided';

    protected function casts(): array
    {
        return [
            'advance_date' => 'date',
            'amount' => 'decimal:2',
            'voided_at' => 'datetime',
            'waived_at' => 'datetime',
            'version' => 'integer',
            'waived_cents' => 'integer',
        ];
    }

    /** Only the records that count (a voided or corrected-away row never reaches payroll, a report total or a balance). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where($query->getModel()->getTable().'.status', self::STATUS_ACTIVE);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    /** The company whose payroll this record belongs to (null = the office, or a record from before company ownership). */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    /** The amount in cents (the money column is decimal(…, 2)). */
    public function amountCents(): int
    {
        return (int) round(((float) $this->amount) * 100);
    }
}
