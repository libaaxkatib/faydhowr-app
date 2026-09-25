<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeContractStatus;
use App\Enums\EmployeePipelineStage;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR §4/§28: Contract is a hard gate to Uniform in
 * this phase (confirmed business decision), the same strictness as Uniform
 * -> Training. A contract must reach 'signed' before the employee's
 * pipeline_stage can advance past contract_pending.
 */
class MarkEmployeeContractSignedAction
{
    public function handle(Employee $employee, EmployeeContract $contract, array $data, Admin $actor): Employee
    {
        return DB::transaction(function () use ($employee, $contract, $data, $actor) {
            $contract->update([
                'status' => EmployeeContractStatus::Signed,
                'signed_date' => $data['signed_date'],
                'signed_document_id' => $data['signed_document_id'] ?? null,
            ]);

            if ($employee->pipeline_stage === EmployeePipelineStage::ContractPending) {
                $employee->update(['pipeline_stage' => EmployeePipelineStage::UniformPending]);
            }

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Contract marked signed for employee '{$employee->full_name}' ({$employee->employee_number}).",
                entityType: EmployeeContract::class,
                entityId: $contract->id,
                metadata: ['employee_id' => $employee->id],
            ));

            return $employee->fresh(['currentContract', 'contracts', 'category', 'department', 'position']);
        });
    }
}
