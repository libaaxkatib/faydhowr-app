<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeContractStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeContract;

/**
 * A new contract is always a new row - never an update of a prior one - so
 * signed historical contracts are never overwritten (docs/HRM_MARKETING_SRS.md
 * HR §28).
 */
class CreateEmployeeContractAction
{
    public function handle(Employee $employee, array $data, Admin $actor): EmployeeContract
    {
        $contract = EmployeeContract::query()->create([
            ...$data,
            'employee_id' => $employee->id,
            'status' => $data['status'] ?? EmployeeContractStatus::Issued->value,
            'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Contract issued for employee '{$employee->full_name}' ({$employee->employee_number}).",
            entityType: EmployeeContract::class,
            entityId: $contract->id,
            metadata: ['employee_id' => $employee->id],
        ));

        return $contract->load('createdBy', 'signedDocument');
    }
}
