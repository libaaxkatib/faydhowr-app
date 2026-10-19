<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money that moves outside a payroll run for a correction: an underpayment paid to the employee, or part of a debt repaid by the employee. */
#[Fillable([
    'payroll_correction_id', 'type', 'amount_cents', 'transaction_date', 'note', 'idempotency_key', 'employee_payment_id',
    'recorded_by', 'voided_at', 'voided_by', 'void_reason',
])]
class PayrollCorrectionTransaction extends Model
{
    public const TYPE_UNDERPAYMENT_PAYMENT = 'underpayment_payment';

    public const TYPE_DEBT_REPAYMENT = 'debt_repayment';

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

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }
}
