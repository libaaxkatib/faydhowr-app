<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeCategory;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeCategoryName;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Renames or re-describes a category. The id never changes, so every employee, request and report that points at it follows the new
 * name. The Office Staff category cannot be renamed: the Office Staff workflow finds it by name.
 */
class UpdateEmployeeCategoryAction
{
    /**
     * @param  array{name?: string, description?: ?string}  $data
     */
    public function handle(EmployeeCategory $category, array $data, Admin $actor): EmployeeCategory
    {
        $isOfficeStaff = EmployeeCategoryName::key($category->name) === EmployeeCategoryName::key(EmployeeCategory::OFFICE_STAFF);

        if ($isOfficeStaff && isset($data['name']) && EmployeeCategoryName::key($data['name']) !== EmployeeCategoryName::key($category->name)) {
            throw new HttpResponseException(ApiResponse::error('The Office Staff category is used by the Office Staff workflow and cannot be renamed.', 'CATEGORY_NAME_LOCKED', 422));
        }

        $beforeName = $category->name;
        $beforeDescription = $category->description;
        $category->update($data);

        if ($category->wasChanged()) {
            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee category '{$beforeName}' updated.",
                entityType: EmployeeCategory::class,
                entityId: $category->id,
                metadata: [
                    'before' => ['name' => $beforeName, 'description' => $beforeDescription],
                    'after' => ['name' => $category->name, 'description' => $category->description],
                ],
            ));
        }

        return $category;
    }
}
