<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 4: recording a leave IS the approved
 * record - no approval-workflow state machine, mirrors the Phase 3
 * payment-ledger philosophy. Every leave period is permanent history.
 */
class RecordEmployeeLeaveAction
{
    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        if ($employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to record leave.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $leave = EmployeeLeave::query()->create([
            ...$data,
            'employee_id' => $employee->id,
            'recorded_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Leave recorded for '{$employee->full_name}' ({$leave->leave_type->label()}).",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: ['leave_id' => $leave->id],
        ));

        return $employee->load('leaves.recordedBy');
    }
}
