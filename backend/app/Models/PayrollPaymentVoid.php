<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The linked correction entry of a voided payroll payment: which line, which original payment, which reversal row, how much, why and by whom.
 * One row per payment (unique), never edited. The original employee payment and its ledger row are untouched.
 */
#[Fillable(['payroll_item_id', 'employee_id', 'original_payment_id', 'reversal_payment_id', 'amount_cents', 'reason', 'voided_by'])]
class PayrollPaymentVoid extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function originalPayment(): BelongsTo
    {
        return $this->belongsTo(EmployeePayment::class, 'original_payment_id');
    }

    public function reversalPayment(): BelongsTo
    {
        return $this->belongsTo(EmployeePayment::class, 'reversal_payment_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'voided_by');
    }
}
