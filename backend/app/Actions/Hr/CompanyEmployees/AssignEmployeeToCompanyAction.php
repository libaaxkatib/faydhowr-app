<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use Illuminate\Support\Facades\DB;

/**
 * Places an EXISTING HR employee at this company. The employee is chosen by id (from the picker); this action never creates or
 * edits an employee record. The company comes from the URL, never from the request body. Duplicate, capacity and succession rules
 * are the existing ones (CreateWorkAssignmentAction); here we add: the employee must be Active and not archived.
 */
class AssignEmployeeToCompanyAction
{
    public function __construct(private readonly CreateWorkAssignmentAction $create) {}

    /** @param  array<string, mixed>  $data */
    public function handle(ClientCompany $company, array $data, Admin $actor): EmployeeWorkAssignment
    {
        $employee = Employee::withTrashed()->findOrFail((int) $data['employee_id']);
        CompanyAssignmentGuard::requireAssignable($employee);

        return DB::transaction(function () use ($company, $data, $actor, $employee): EmployeeWorkAssignment {
            $assignment = $this->create->handle($employee, [
                'client_company_id' => $company->id,
                'work_location_id' => $data['work_location_id'] ?? null,
                'position_id' => $data['position_id'] ?? null,
                'assignment_type' => $data['assignment_type'],
                'start_date' => $data['start_date'],
                'salary_amount' => $data['salary_amount'],
                'salary_currency' => strtoupper((string) $data['salary_currency']),
                'salary_frequency' => $data['salary_frequency'],
                'notes' => $data['notes'] ?? null,
                'override_capacity' => $data['override_capacity'] ?? false,
                'correction_reason' => $data['correction_reason'] ?? null,
            ], $actor);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Employee '{$employee->full_name}' ({$employee->employee_number}) added to company '{$company->name}'.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $assignment->id,
                metadata: ['event' => 'assigned', 'company_id' => $company->id, 'employee_id' => $employee->id, 'assignment_type' => $assignment->assignment_type->value, 'start_date' => $assignment->start_date->toDateString()],
            ));

            return $assignment;
        });
    }
}
