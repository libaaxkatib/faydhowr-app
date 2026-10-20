<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Money that moves outside a payroll run for a correction: an underpayment paid to the employee, or part of a debt repaid by the employee. */
#[Fillable([
    'payroll_correction_id', 'type', 'amount_cents', 'transaction_date', 'note', 'reason', 'balance_before_cents', 'balance_after_cents',
    'reverses_transaction_id', 'idempotency_key', 'employee_payment_id', 'recorded_by', 'voided_at', 'voided_by', 'void_reason',
])]
class PayrollCorrectionTransaction extends Model
{
    public const TYPE_UNDERPAYMENT_PAYMENT = 'underpayment_payment';

    /** Cancels an underpayment payment (Super Admin): linked to the payment, which stays in the ledger untouched. */
    public const TYPE_UNDERPAYMENT_REVERSAL = 'underpayment_payment_reversal';

    public const TYPE_DEBT_REPAYMENT = 'debt_repayment';

    /** Part or all of a debt is forgiven (Super Admin only, with a reason); the debt record and its history stay. */
    public const TYPE_DEBT_WRITE_OFF = 'debt_write_off';

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'voided_at' => 'datetime'];
    }

    public function correction(): BelongsTo
    {
        return $this->belongsTo(PayrollCorrection::class, 'payroll_correction_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'voided_by');
    }

    /** The reversal row that cancels this payment, if any. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    /** Voided = marked void (a debt repayment) or cancelled by a linked reversal (an underpayment payment). */
    public function isVoided(): bool
    {
        return $this->voided_at !== null || $this->reversal()->exists();
    }
}
