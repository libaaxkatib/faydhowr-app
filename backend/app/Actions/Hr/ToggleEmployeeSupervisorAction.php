<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3: Supervisor Pool is a cross-cutting
 * flag, not an assignment to a specific location/team (that's out of this
 * phase's scope). Only entering the pool requires Active status, mirroring
 * the Work Assignment precedent; leaving it is always allowed.
 */
class ToggleEmployeeSupervisorAction
{
    public function handle(Employee $employee, bool $isSupervisor, Admin $actor): Employee
    {
        if ($isSupervisor && $employee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' must be Active to join the Supervisor Pool.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        $employee->update([
            'is_supervisor' => $isSupervisor,
            'supervisor_since' => $isSupervisor ? now() : null,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: $isSupervisor
                ? "Employee '{$employee->full_name}' added to the Supervisor Pool."
                : "Employee '{$employee->full_name}' removed from the Supervisor Pool.",
            entityType: Employee::class,
            entityId: $employee->id,
        ));

        return $employee->load('category', 'department', 'position');
    }
}
