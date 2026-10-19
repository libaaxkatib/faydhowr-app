<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month's recovery of an overpayment. Unique per correction and month. */
#[Fillable(['payroll_correction_id', 'payroll_run_id', 'period', 'amount_cents', 'status'])]
class PayrollRecoveryDeduction extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_APPLIED = 'applied';

    public function correction(): BelongsTo
    {
        return $this->belongsTo(PayrollCorrection::class, 'payroll_correction_id');
    }
}
