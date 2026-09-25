<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 4: the daily roster - every Office
 * Staff employee (active work assignment at an 'office' work location)
 * annotated with that day's attendance status, or null if not yet marked.
 */
class ListAttendanceForDateAction
{
    public function handle(string $date): Collection
    {
        return Employee::query()
            ->whereHas('activeWorkAssignments.workLocation', fn ($q) => $q->where('location_type', 'office'))
            ->with(['category', 'position', 'attendances' => fn ($q) => $q->whereDate('date', $date)])
            ->orderBy('full_name')
            ->get()
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'full_name' => $employee->full_name,
                'employee_category_name' => $employee->category?->name,
                'position_name' => $employee->position?->name,
                'attendance_status' => $employee->attendances->first()?->status->value,
                'attendance_notes' => $employee->attendances->first()?->notes,
            ]);
    }
}
