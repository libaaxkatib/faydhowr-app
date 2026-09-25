<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeDocumentCategory;

/**
 * docs/HRM_MARKETING_SRS.md HR §27: HR can add document categories beyond
 * the seeded starter list without a code change.
 */
class CreateEmployeeDocumentCategoryAction
{
    public function handle(array $data, Admin $actor): EmployeeDocumentCategory
    {
        $category = EmployeeDocumentCategory::query()->create($data);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Document category '{$category->name}' created.",
            entityType: EmployeeDocumentCategory::class,
            entityId: $category->id,
        ));

        return $category;
    }
}
