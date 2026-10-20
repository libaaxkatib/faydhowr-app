<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeRegistrationWorkflow;
use App\Support\Hr\EmployeeTransitionGuard;
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
    public function __construct(
        private readonly EmployeeTransitionGuard $guard,
        private readonly EmployeeRegistrationWorkflow $workflow = new EmployeeRegistrationWorkflow,
    ) {}

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

        $this->guard->authorize(EmployeeStatus::Inactive, EmployeeStatus::Active, EmployeeTransitionGuard::CONTEXT_REHIRE, $actor);
        $this->workflow->requireEligible($employee, 'cannot be rehired to Active');

        return DB::transaction(function () use ($employee, $note, $actor) {
            $previousStage = $employee->pipeline_stage;
            $employee->update(['status' => EmployeeStatus::Active, 'pipeline_stage' => null]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'from_status' => EmployeeStatus::Inactive,
                'to_status' => EmployeeStatus::Active,
                'changed_by' => $actor->id,
                'note' => trim(($note ?: 'Rehired.').($previousStage === null ? '' : " Pipeline stage '{$previousStage->label()}' cleared because the person is Active again (kept here as history).")),
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
