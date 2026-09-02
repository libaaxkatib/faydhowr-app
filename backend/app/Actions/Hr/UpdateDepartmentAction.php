<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Department;

class UpdateDepartmentAction
{
    public function handle(Department $department, array $data, Admin $actor): Department
    {
        $department->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Department '{$department->name}' updated.",
            entityType: Department::class,
            entityId: $department->id,
        ));

        return $department;
    }
}
