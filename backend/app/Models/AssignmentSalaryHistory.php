<?php

namespace App\Models;

use App\Enums\SalaryFrequency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['work_assignment_id', 'effective_from', 'amount', 'currency', 'frequency', 'reason', 'changed_by'])]
class AssignmentSalaryHistory extends Model
{
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'amount' => 'decimal:2',
            'frequency' => SalaryFrequency::class,
        ];
    }

    public function workAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class, 'work_assignment_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'changed_by');
    }
}
