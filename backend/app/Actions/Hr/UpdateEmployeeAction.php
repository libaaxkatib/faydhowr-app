<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;

class UpdateEmployeeAction
{
    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        $employee->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Employee '{$employee->full_name}' ({$employee->employee_number}) updated.",
            entityType: Employee::class,
            entityId: $employee->id,
        ));

        return $employee->load(['category', 'department', 'position']);
    }
}
