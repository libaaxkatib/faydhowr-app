<?php

namespace App\Actions\Hr;

use App\Enums\AttendanceStatus;
use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 4: unlike every other ledger action in
 * this app, a single day is one fact - marking the same employee+date again
 * corrects it in place (updateOrCreate) rather than creating a superseding
 * row, per the employee_attendances migration's unique constraint.
 */
class MarkEmployeeAttendanceAction
{
    public function handle(Employee $employee, string $date, AttendanceStatus $status, ?string $notes, Admin $actor): EmployeeAttendance
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to record attendance.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $attendance = EmployeeAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->first() ?? new EmployeeAttendance(['employee_id' => $employee->id, 'date' => $date]);

        $attendance->fill(['status' => $status, 'notes' => $notes, 'recorded_by' => $actor->id]);
        $attendance->save();

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Attendance for '{$employee->full_name}' on {$date} marked {$status->label()}.",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['date' => $date, 'status' => $status->value],
        ));

        return $attendance->load('recordedBy');
    }
}
