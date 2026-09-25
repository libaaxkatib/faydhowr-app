<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeePipelineStage;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeGuarantor;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR §6: "Damiin complete -> Uniform Pending" is
 * condition-driven, gated behind Contract per this phase's confirmed
 * business decision (Contract sits between Guarantor and Uniform and is
 * itself a hard gate - see MarkEmployeeContractSignedAction). Keeps writing
 * employees.guarantor_confirmed_at for backward compatibility with the
 * pre-Phase-1 timestamp-only implementation.
 */
class VerifyEmployeeGuarantorAction
{
    public function handle(Employee $employee, EmployeeGuarantor $guarantor, Admin $actor): Employee
    {
        return DB::transaction(function () use ($employee, $guarantor, $actor) {
            $guarantor->update([
                'verified_at' => Date::now(),
                'verified_by' => $actor->id,
            ]);

            $employee->update([
                'guarantor_confirmed_at' => Date::now(),
                'pipeline_stage' => EmployeePipelineStage::ContractPending,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Guarantor verified for employee '{$employee->full_name}' ({$employee->employee_number}).",
                entityType: Employee::class,
                entityId: $employee->id,
            ));

            return $employee->fresh(['guarantor', 'category', 'department', 'position']);
        });
    }
}
