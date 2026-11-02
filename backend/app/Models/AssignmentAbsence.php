<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One absence of the holder of a Permanent assignment. The assignment row itself is never replaced; payroll does not pay the original for the days of this
 * window (from `absent_from` up to the day before `returned_on`, or without end while the person is still away).
 */
#[Fillable(['work_assignment_id', 'last_working_day', 'absent_from', 'returned_on', 'terminated_on', 'created_by'])]
class AssignmentAbsence extends Model
{
    protected function casts(): array
    {
        return [
            'last_working_day' => 'date',
            'absent_from' => 'date',
            'returned_on' => 'date',
            'terminated_on' => 'date',
        ];
    }

    public function workAssignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkAssignment::class, 'work_assignment_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AssignmentCycleEvent::class, 'absence_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->returned_on === null;
    }
}
