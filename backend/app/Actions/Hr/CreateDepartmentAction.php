<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Department;

class CreateDepartmentAction
{
    public function handle(array $data, Admin $actor): Department
    {
        $department = Department::query()->create($data);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Department '{$department->name}' created.",
            entityType: Department::class,
            entityId: $department->id,
        ));

        return $department;
    }
}
