<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeUniform;

class UpdateEmployeeUniformAction
{
    public function handle(Employee $employee, array $data, Admin $actor): EmployeeUniform
    {
        $uniform = $employee->uniform()->first();

        if ($uniform) {
            $uniform->update($data);
        } else {
            $uniform = EmployeeUniform::query()->create([
                ...$data,
                'employee_id' => $employee->id,
            ]);
        }

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Uniform status updated for employee '{$employee->full_name}' ({$employee->employee_number}).",
            entityType: EmployeeUniform::class,
            entityId: $uniform->id,
            metadata: ['employee_id' => $employee->id],
        ));

        return $uniform->fresh('confirmedBy');
    }
}
