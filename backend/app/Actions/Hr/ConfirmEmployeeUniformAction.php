<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeUniformStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeUniform;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR §7-8: "a person cannot be called to Training
 * until their required uniform is purchased/received and confirmed" - the
 * explicit RULE. Confirming here is what unlocks pipeline_stage=need_training.
 * Enforced the same way CreateWorkAssignmentAction enforces its capacity
 * rule: a real 422 (EMPLOYEE_CONTRACT_NOT_SIGNED), not a silent no-op,
 * since the employee can only be at pipeline_stage=uniform_pending after
 * their Contract has been marked signed (the confirmed hard-gate for this
 * phase).
 */
class ConfirmEmployeeUniformAction
{
    public function handle(Employee $employee, EmployeeUniform $uniform, Admin $actor): Employee
    {
        if ($employee->pipeline_stage !== EmployeePipelineStage::UniformPending) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'Uniform cannot be confirmed until the employee has reached the Uniform Pending stage (Contract must be signed first).',
                    'EMPLOYEE_CONTRACT_NOT_SIGNED',
                    422,
                ),
            );
        }

        return DB::transaction(function () use ($employee, $uniform, $actor) {
            $uniform->update([
                'status' => EmployeeUniformStatus::Confirmed,
                'confirmed_at' => Date::now(),
                'confirmed_by' => $actor->id,
            ]);

            $employee->update(['pipeline_stage' => EmployeePipelineStage::NeedTraining]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Uniform confirmed for employee '{$employee->full_name}' ({$employee->employee_number}).",
                entityType: EmployeeUniform::class,
                entityId: $uniform->id,
                metadata: ['employee_id' => $employee->id],
            ));

            return $employee->fresh(['uniform', 'category', 'department', 'position']);
        });
    }
}
