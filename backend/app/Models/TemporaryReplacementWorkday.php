<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'temporary_replacement_id', 'work_assignment_id', 'replacement_employee_id', 'work_date', 'status', 'notes',
    'recorded_by', 'verified_at', 'verified_by', 'voided_at', 'voided_by', 'void_reason',
])]
class TemporaryReplacementWorkday extends Model
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_VOID = 'void';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'verified_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(TemporaryReplacement::class, 'temporary_replacement_id');
    }

    public function workAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class, 'work_assignment_id');
    }

    public function replacementEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'replacement_employee_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'recorded_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'verified_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'voided_by');
    }
}
