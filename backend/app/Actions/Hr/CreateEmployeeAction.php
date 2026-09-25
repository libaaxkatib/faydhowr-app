<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Support\Hr\EmployeeCodeGenerator;
use Illuminate\Support\Facades\DB;

class CreateEmployeeAction
{
    public function __construct(private EmployeeCodeGenerator $codeGenerator) {}

    public function handle(array $data, Admin $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor) {
            $employee = Employee::query()->create([
                ...$data,
                'employee_number' => $this->codeGenerator->next(),
                'status' => EmployeeStatus::Applicant,
                'pipeline_stage' => EmployeePipelineStage::DamiinNeeded,
                'created_by' => $actor->id,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'from_status' => null,
                'to_status' => EmployeeStatus::Applicant,
                'changed_by' => $actor->id,
                'note' => 'Registration created.',
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Employee '{$employee->full_name}' registered ({$employee->employee_number}).",
                entityType: Employee::class,
                entityId: $employee->id,
            ));

            return $employee->load(['category', 'department', 'position']);
        });
    }
}
