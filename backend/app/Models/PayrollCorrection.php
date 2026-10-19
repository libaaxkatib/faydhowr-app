<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Super Admin correction of data that reaches a closed payroll month. The closed run itself is never changed. */
#[Fillable([
    'batch_key', 'employee_id', 'work_assignment_id', 'payroll_run_id', 'payroll_item_id', 'period', 'kind', 'action',
    'recorded_net_cents', 'corrected_net_cents', 'difference_cents', 'outstanding_cents', 'status', 'reason',
    'old_values', 'new_values', 'corrected_by',
])]
class PayrollCorrection extends Model
{
    public const KIND_OVERPAYMENT = 'overpayment';

    public const KIND_UNDERPAYMENT = 'underpayment';

    public const STATUS_OPEN = 'open';

    public const STATUS_RECOVERED = 'recovered';

    /** Underpayments: recorded and visible, but no payment rule has been approved, so nothing is paid automatically. */
    public const STATUS_PENDING_POLICY = 'pending_policy';

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'corrected_by');
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(PayrollRecoveryDeduction::class)->orderBy('period');
    }

    /** What was recovered so far = the recoveries of approved/finalized months (a draft month is only scheduled). */
    public function recoveredCents(): int
    {
        return (int) $this->recoveries()->where('status', PayrollRecoveryDeduction::STATUS_APPLIED)->sum('amount_cents');
    }

    /** Recomputes the outstanding balance from the ledger (the ledger is the source of truth) and closes the correction when repaid. */
    public function refreshBalance(): void
    {
        if ($this->kind !== self::KIND_OVERPAYMENT) {
            return;
        }

        $outstanding = max(0, $this->difference_cents - $this->recoveredCents());
        $this->forceFill(['outstanding_cents' => $outstanding, 'status' => $outstanding === 0 ? self::STATUS_RECOVERED : self::STATUS_OPEN])->save();
    }
}
