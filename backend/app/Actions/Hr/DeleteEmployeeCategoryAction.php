<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkforceRequest;
use App\Support\ApiResponse;
use App\Support\Hr\ReferenceUsage;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a category nobody uses. A category that any employee (archived ones count: they are history) or workforce request points at is
 * never deleted - the answer names what still uses it and points at archiving instead. The Office Staff category belongs to the Office Staff
 * workflow and can be neither deleted nor archived. The check and the delete run in one transaction behind a row lock.
 */
class DeleteEmployeeCategoryAction
{
    public function handle(EmployeeCategory $category, Admin $actor): void
    {
        if ($category->isSystem()) {
            throw new HttpResponseException(ApiResponse::error('The Office Staff category is used by the Office Staff workflow and cannot be deleted.', 'CATEGORY_SYSTEM', 422));
        }

        [$name, $id] = DB::transaction(function () use ($category): array {
            $locked = EmployeeCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

            $employees = Employee::withTrashed()->where('employee_category_id', $locked->id)->count();
            $archived = Employee::onlyTrashed()->where('employee_category_id', $locked->id)->count();
            $employeePhrase = ReferenceUsage::phrase($employees, 'employee');
            if ($employeePhrase !== null && $archived > 0) {
                $employeePhrase .= " ({$archived} archived)";
            }

            $usedBy = ReferenceUsage::join([
                $employeePhrase,
                ReferenceUsage::phrase(WorkforceRequest::query()->where('employee_category_id', $locked->id)->count(), 'workforce request'),
            ]);

            if ($usedBy !== null) {
                throw new HttpResponseException(ApiResponse::error(
                    "This category cannot be deleted because it is still used by {$usedBy}. Archive it instead: it stays on those records but can no longer be chosen for new ones.",
                    'CATEGORY_IN_USE',
                    422,
                ));
            }

            $name = $locked->name;
            $id = $locked->id;
            $locked->delete();

            return [$name, $id];
        });

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Employee category '{$name}' deleted.",
            entityType: EmployeeCategory::class,
            entityId: $id,
        ));
    }
}
