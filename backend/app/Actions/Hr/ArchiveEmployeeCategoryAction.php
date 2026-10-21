<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeCategory;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Archives or restores a category. Archiving only hides it from the lists used to register people or raise requests; every employee, request
 * and report that already uses it is untouched. The Office Staff category cannot be archived.
 */
class ArchiveEmployeeCategoryAction
{
    public function handle(EmployeeCategory $category, bool $archive, Admin $actor): EmployeeCategory
    {
        if ($archive && $category->isSystem()) {
            throw new HttpResponseException(ApiResponse::error('The Office Staff category is used by the Office Staff workflow and cannot be archived.', 'CATEGORY_SYSTEM', 422));
        }

        if ($archive === $category->isArchived()) {
            throw new HttpResponseException(ApiResponse::error($archive ? 'This category is already archived.' : 'This category is not archived.', 'CATEGORY_ARCHIVE_NO_CHANGE', 422));
        }

        $category->forceFill(['archived_at' => $archive ? now() : null])->save();

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Employee category '{$category->name}' ".($archive ? 'archived' : 'restored').'.',
            entityType: EmployeeCategory::class,
            entityId: $category->id,
            metadata: ['archived' => $archive],
        ));

        return $category;
    }
}
