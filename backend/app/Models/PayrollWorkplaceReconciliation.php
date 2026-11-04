<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry that settles an OVERPAID workplace (it was paid more than its corrected payroll says). Append-only: a mistaken entry is voided with a
 * reason and then no longer counts. It never changes a payment and never moves money by itself; it records how the excess was settled.
 */
#[Fillable([
    'payroll_run_id', 'period', 'client_company_id', 'work_location_id', 'amount_cents', 'method', 'reason', 'settled_on', 'idempotency_key',
    'recorded_by', 'recorded_by_role', 'voided_at', 'voided_by', 'void_reason',
])]
class PayrollWorkplaceReconciliation extends Model
{
    public const METHODS = ['returned', 'carried_forward', 'other'];

    protected function casts(): array
    {
        return ['settled_on' => 'date', 'voided_at' => 'datetime', 'amount_cents' => 'integer'];
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
