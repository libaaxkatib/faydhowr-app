<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Enums\TemporaryReplacementStatus;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3: a replacement covers an EXISTING
 * work assignment - the original employee's assignment is never touched or
 * ended, this just records who is standing in. "Multi-company replacement
 * work" needs no special handling: the replacement is picked from the full
 * Active-employee list, independent of which company they normally work for.
 */
class CreateTemporaryReplacementAction
{
    public function handle(EmployeeWorkAssignment $workAssignment, Employee $replacementEmployee, array $data, Admin $actor): TemporaryReplacement
    {
        if ($workAssignment->status !== WorkAssignmentStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'This work assignment is not currently active.',
                    'WORK_ASSIGNMENT_NOT_ACTIVE',
                    422,
                ),
            );
        }

        if ($replacementEmployee->status !== EmployeeStatus::Active) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$replacementEmployee->full_name}' must be Active to cover a replacement.",
                    'EMPLOYEE_NOT_ACTIVE',
                    422,
                ),
            );
        }

        if ((int) $replacementEmployee->id === (int) $workAssignment->employee_id) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'An employee cannot be recorded as their own replacement.',
                    'EMPLOYEE_CANNOT_REPLACE_SELF',
                    422,
                ),
            );
        }

        return DB::transaction(function () use ($workAssignment, $replacementEmployee, $data, $actor) {
            $replacement = TemporaryReplacement::query()->create([
                ...$data,
                'work_assignment_id' => $workAssignment->id,
                'replacement_employee_id' => $replacementEmployee->id,
                'status' => TemporaryReplacementStatus::Active,
                'created_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Employee '{$replacementEmployee->full_name}' recorded as a temporary replacement for work assignment #{$workAssignment->id}.",
                entityType: TemporaryReplacement::class,
                entityId: $replacement->id,
            ));

            return $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy');
        });
    }
}
