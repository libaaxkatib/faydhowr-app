<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Actions\Hr\EndWorkAssignmentAction;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Illuminate\Support\Facades\DB;

/**
 * Return Original Employee. The call names the CURRENT Replacement assignment. In one transaction it ends that assignment on the
 * day before the return date and gives the original employee a NEW Permanent assignment starting on the return date, linked to the
 * replacement's. Nothing is deleted or rewritten: the original's first assignment, the replacement's assignment and every payroll
 * and attendance record stay as they were, and the chain original -> replacement -> returned original stays readable.
 *
 * Only an assignment marked Replacement can be returned from - a replacement who was made Permanent keeps the position (use
 * Replace to change that). The original must still be an Active employee. A change reaching into an approved or finalized payroll
 * month is refused.
 */
class ReturnCompanyEmployeeAction
{
    public function __construct(
        private readonly EndWorkAssignmentAction $end,
        private readonly CreateWorkAssignmentAction $create,
        private readonly PayrollPeriodLock $lock = new PayrollPeriodLock,
    ) {}

    /**
     * @param  array<string, mixed>  $data  return_date, optional position_id, salary_amount, salary_currency, salary_frequency, notes
     * @return array{replacement: EmployeeWorkAssignment, returned: EmployeeWorkAssignment}
     */
    public function handle(ClientCompany $company, EmployeeWorkAssignment $replacement, array $data, Admin $actor): array
    {
        CompanyAssignmentGuard::requireOpen($replacement);

        if (($replacement->assignment_type ?? AssignmentType::Permanent) !== AssignmentType::Replacement) {
            CompanyAssignmentGuard::fail('Only an employee marked Replacement can be returned from. This assignment is Permanent; use Replace Employee to change who holds the position.', 'RETURN_NOT_A_REPLACEMENT');
        }

        $original = $replacement->replaces()->with(['employee', 'position'])->first();

        if ($original === null || $original->employee === null) {
            CompanyAssignmentGuard::fail('This replacement is not linked to an original employee, so there is nobody to return. Use Replace Employee instead.', 'RETURN_NO_ORIGINAL');
        }

        CompanyAssignmentGuard::requireAssignable($original->employee);

        $returnDate = CompanyAssignmentGuard::date($data['return_date']);
        $lastDay = $returnDate->subDay();

        if ($lastDay->lt(CompanyAssignmentGuard::date($replacement->start_date->toDateString()))) {
            CompanyAssignmentGuard::fail("The replacement started on {$replacement->start_date->toDateString()}; the return date must be after that day.", 'RETURN_BEFORE_REPLACEMENT_STARTED');
        }

        $this->lock->assertAssignmentDateChangeAllowed($returnDate->toDateString());

        $replacement->loadMissing('employee');

        return DB::transaction(function () use ($company, $replacement, $original, $data, $actor, $returnDate, $lastDay): array {
            $this->end->handle($replacement, [
                'end_date' => $lastDay->toDateString(),
                'note' => "Ended because {$original->employee->full_name} ({$original->employee->employee_number}) returned on {$returnDate->toDateString()}.",
            ], $actor);

            $returned = $this->create->handle($original->employee, [
                'client_company_id' => $company->id,
                'work_location_id' => $original->work_location_id,
                'replaces_assignment_id' => $replacement->id,
                'position_id' => array_key_exists('position_id', $data) ? $data['position_id'] : $original->position_id,
                'assignment_type' => AssignmentType::Permanent->value,
                'start_date' => $returnDate->toDateString(),
                'salary_amount' => $data['salary_amount'] ?? $original->salary_amount,
                'salary_currency' => strtoupper((string) ($data['salary_currency'] ?? $original->salary_currency)),
                'salary_frequency' => $data['salary_frequency'] ?? $original->salary_frequency->value,
                'notes' => $data['notes'] ?? null,
            ], $actor);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "'{$original->employee->full_name}' ({$original->employee->employee_number}) returned to '{$company->name}'; the replacement '{$replacement->employee?->full_name}' ended on {$lastDay->toDateString()}.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $replacement->id,
                metadata: [
                    'event' => 'returned',
                    'company_id' => $company->id,
                    'original_employee_id' => $original->employee_id,
                    'original_first_assignment_id' => $original->id,
                    'replacement_assignment_id' => $replacement->id,
                    'replacement_employee_id' => $replacement->employee_id,
                    'replacement_last_day' => $lastDay->toDateString(),
                    'returned_assignment_id' => $returned->id,
                    'return_date' => $returnDate->toDateString(),
                ],
            ));

            return ['replacement' => $replacement->refresh()->load(['employee', 'position']), 'returned' => $returned->load(['employee', 'position'])];
        });
    }
}
