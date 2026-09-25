<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeGuarantor;

class CreateOrUpdateEmployeeGuarantorAction
{
    public function handle(Employee $employee, array $data, Admin $actor): EmployeeGuarantor
    {
        $guarantor = $employee->guarantor()->first();

        if ($guarantor) {
            $guarantor->update($data);
        } else {
            $guarantor = EmployeeGuarantor::query()->create([
                ...$data,
                'employee_id' => $employee->id,
                'created_by' => $actor->id,
            ]);
        }

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Guarantor information recorded for employee '{$employee->full_name}' ({$employee->employee_number}).",
            entityType: EmployeeGuarantor::class,
            entityId: $guarantor->id,
            metadata: ['employee_id' => $employee->id],
        ));

        return $guarantor->fresh(['verifiedBy', 'createdBy']);
    }
}
