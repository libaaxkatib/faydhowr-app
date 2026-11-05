<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment recorded for a whole workplace in a payroll month (see the create migration). Append-only: a mistaken payment is voided with a
 * reason and then no longer counts toward the workplace's paid total.
 */
#[Fillable([
    'payroll_run_id', 'period', 'client_company_id', 'work_location_id', 'amount_cents', 'payment_date', 'note', 'reference', 'idempotency_key',
    'recorded_by', 'voided_at', 'voided_by', 'void_reason',
])]
class PayrollWorkplacePayment extends Model
{
    protected function casts(): array
    {
        return ['payment_date' => 'date', 'voided_at' => 'datetime', 'amount_cents' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'voided_by');
    }
}
