<?php

namespace App\Models;

use App\Enums\LeaveType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'leave_type', 'approval_status', 'start_date', 'end_date', 'notes', 'recorded_by', 'decided_by', 'decided_at', 'decision_note'])]
class EmployeeLeave extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'leave_type' => LeaveType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'decided_at' => 'datetime',
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

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by');
    }
}
