<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'penalty_date', 'reason', 'deduction_amount', 'currency', 'payroll_period', 'notes', 'recorded_by'])]
class EmployeePenalty extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'penalty_date' => 'date',
            'deduction_amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }
}
