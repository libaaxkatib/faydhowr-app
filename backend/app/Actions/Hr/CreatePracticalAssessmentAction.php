<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeePracticalAssessment;

class CreatePracticalAssessmentAction
{
    public function handle(Employee $employee, array $data, Admin $actor): EmployeePracticalAssessment
    {
        $assessment = EmployeePracticalAssessment::query()->create([
            ...$data,
            'employee_id' => $employee->id,
            'assessed_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Practical assessment recorded for employee '{$employee->full_name}'.",
            entityType: EmployeePracticalAssessment::class,
            entityId: $assessment->id,
            metadata: ['employee_id' => $employee->id, 'result' => $data['result']],
        ));

        return $assessment->load('assessedBy');
    }
}
