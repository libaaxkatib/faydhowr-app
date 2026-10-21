<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeCategory;

class CreateEmployeeCategoryAction
{
    /** @param  array{name: string, description?: ?string}  $data */
    public function handle(array $data, Admin $actor): EmployeeCategory
    {
        $category = EmployeeCategory::query()->create($data);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Employee category '{$category->name}' created.",
            entityType: EmployeeCategory::class,
            entityId: $category->id,
        ));

        return $category;
    }
}
