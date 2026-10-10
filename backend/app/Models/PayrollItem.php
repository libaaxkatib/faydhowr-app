<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'payroll_run_id', 'period', 'employee_id', 'work_assignment_id', 'employee_name', 'employee_number',
    'company_name', 'workplace_name', 'eligible_days', 'days_in_month', 'gross_cents',
    'replacement_deduction_cents', 'other_deduction_cents', 'adjustment_cents', 'net_cents', 'calc_snapshot',
    'payment_status', 'paid_at', 'employee_payment_id', 'voided_at', 'voided_by', 'void_reason',
])]
class PayrollItem extends Model
{
    protected function casts(): array
    {
        return [
            'calc_snapshot' => 'array',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class, 'work_assignment_id');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(PayrollItemDeduction::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }
}
