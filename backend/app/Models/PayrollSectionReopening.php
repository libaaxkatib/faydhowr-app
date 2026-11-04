<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reopening of a FINALIZED company payroll: who reopened it, in which role, when and why, and what it held (totals, money already paid) - plus,
 * once it is finalized again, what it holds now. The finalized figures are kept here; the payments recorded for the company are never touched.
 */
#[Fillable([
    'payroll_run_id', 'payroll_run_section_id', 'period', 'client_company_id', 'reason', 'reopened_by', 'reopened_by_role', 'reopened_at',
    'previous_finalized_at', 'previous_finalized_by', 'totals_before', 'paid_cents_at_reopen', 'payment_count_at_reopen',
    'refinalized_at', 'refinalized_by', 'totals_after',
])]
class PayrollSectionReopening extends Model
{
    protected function casts(): array
    {
        return [
            'reopened_at' => 'datetime',
            'previous_finalized_at' => 'datetime',
            'refinalized_at' => 'datetime',
            'totals_before' => 'array',
            'totals_after' => 'array',
            'paid_cents_at_reopen' => 'integer',
            'payment_count_at_reopen' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(PayrollRunSection::class, 'payroll_run_section_id');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reopened_by');
    }

    public function refinalizedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'refinalized_by');
    }
}
