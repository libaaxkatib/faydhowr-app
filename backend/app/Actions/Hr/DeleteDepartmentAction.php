<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Department;

class DeleteDepartmentAction
{
    public function handle(Department $department, Admin $actor): void
    {
        $name = $department->name;
        $id = $department->id;

        $department->delete();

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Department '{$name}' deleted.",
            entityType: Department::class,
            entityId: $id,
        ));
    }
}
