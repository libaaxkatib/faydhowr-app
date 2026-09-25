<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3: a rehire reactivates the same
 * employee record directly to Active - no pipeline restart, per the
 * confirmed Phase 3 scope decision (they were already fully vetted the
 * first time). rehire_eligible on their latest EmployeeSeparation is
 * advisory only, shown in the UI - never read/enforced here.
 */
class RehireEmployeeAction
{
    public function handle(Employee $employee, ?string $note, Admin $actor): Employee
    {
        if ($employee->status !== EmployeeStatus::Inactive) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' is not currently separated.",
                    'EMPLOYEE_NOT_SEPARATED',
                    422,
                ),
            );
        }

        return DB::transaction(function () use ($employee, $note, $actor) {
            $employee->update(['status' => EmployeeStatus::Active]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'from_status' => EmployeeStatus::Inactive,
                'to_status' => EmployeeStatus::Active,
                'changed_by' => $actor->id,
                'note' => $note ?: 'Rehired.',
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$employee->full_name}' rehired.",
                entityType: Employee::class,
                entityId: $employee->id,
            ));

            return $employee->load('category', 'department', 'position', 'statusHistories.changedBy', 'separations.separatedBy', 'latestSeparation.separatedBy');
        });
    }
}
