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

    /** Still owed: by the employee (overpayment) or to the employee (underpayment). */
    public const STATUS_OPEN = 'open';

    /** An overpayment that has been paid back in full (payroll deductions and recorded repayments). */
    public const STATUS_RECOVERED = 'recovered';

    /** An underpayment that has been paid to the employee in full. */
    public const STATUS_SETTLED = 'settled';

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

    public function transactions(): HasMany
    {
        return $this->hasMany(PayrollCorrectionTransaction::class)->orderBy('id');
    }

    /** What payroll deductions of approved/finalized months have recovered (a draft month is only scheduled). */
    public function recoveredCents(): int
    {
        return (int) $this->recoveries()->where('status', PayrollRecoveryDeduction::STATUS_APPLIED)->sum('amount_cents');
    }

    /** Repayments / payments recorded outside payroll that have not been voided. */
    public function transactionCents(string $type): int
    {
        return (int) $this->transactions()->where('type', $type)->whereNull('voided_at')->sum('amount_cents');
    }

    /**
     * Overpayment: still to be recovered = the overpayment - applied payroll deductions - recorded repayments.
     * Underpayment: still to be paid = the underpayment - payments recorded. The ledgers are the source of truth.
     */
    public function refreshBalance(): void
    {
        if ($this->kind === self::KIND_OVERPAYMENT) {
            $outstanding = max(0, (int) $this->difference_cents - $this->recoveredCents() - $this->transactionCents(PayrollCorrectionTransaction::TYPE_DEBT_REPAYMENT));
            $done = self::STATUS_RECOVERED;
        } else {
            $outstanding = max(0, abs((int) $this->difference_cents) - $this->transactionCents(PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT));
            $done = self::STATUS_SETTLED;
        }

        $this->forceFill(['outstanding_cents' => $outstanding, 'status' => $outstanding === 0 ? $done : self::STATUS_OPEN])->save();
    }

    /** How much more may be recorded as a repayment/payment now: what is outstanding minus what draft payroll months already schedule. */
    public function availableToSettleCents(): int
    {
        $scheduled = $this->kind === self::KIND_OVERPAYMENT
            ? (int) $this->recoveries()->where('status', PayrollRecoveryDeduction::STATUS_SCHEDULED)->sum('amount_cents')
            : 0;

        return max(0, (int) $this->outstanding_cents - $scheduled);
    }
}
