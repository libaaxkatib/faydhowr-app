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
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use Illuminate\Support\Facades\DB;

/**
 * Return Original Employee. The call names the CURRENT Replacement assignment. In one transaction it ends that assignment on the
 * day before the return date and gives the original employee a NEW Permanent assignment starting on the return date, linked to the
 * replacement's. Nothing is deleted or rewritten: the original's first assignment, the replacement's assignment and every payroll
 * and attendance record stay as they were, and the chain original -> replacement -> returned original stays readable.
 *
 * Only an assignment marked Replacement can be returned from - a replacement who was made Permanent keeps the position (use
 * Replace to change that). The original must still be an Active employee. The salary of the new Permanent assignment is never
 * assumed: HR must confirm or edit it (the previous salary is only the proposal on the screen), so it is a required input here.
 * A change reaching into an approved or finalized payroll month is a Super Admin correction (reason required) - see
 * ClosedPayrollCorrection.
 */
class ReturnCompanyEmployeeAction
{
    public function __construct(
        private readonly EndWorkAssignmentAction $end,
        private readonly CreateWorkAssignmentAction $create,
        private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection,
    ) {}

    /**
     * @param  array<string, mixed>  $data  return_date, salary_amount, salary_currency, salary_frequency (all confirmed by HR), optional position_id, notes
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

        foreach (['salary_amount', 'salary_currency', 'salary_frequency'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                CompanyAssignmentGuard::fail('Confirm or edit the salary of the returning employee before saving; it is not assumed to be unchanged.', 'RETURN_SALARY_REQUIRED', [$field => ['The salary must be confirmed.']]);
            }
        }

        $replacement->loadMissing('employee');

        return $this->corrections->run($actor, 'returned', [$replacement->employee_id, $original->employee_id], $returnDate->toDateString(), $data['correction_reason'] ?? null, [
            'assignment_id' => $replacement->id,
            'old' => ['replacement_assignment_id' => $replacement->id, 'end_date' => $replacement->end_date?->toDateString()],
            'new' => ['return_date' => $returnDate->toDateString(), 'replacement_last_day' => $lastDay->toDateString(), 'salary_amount' => $data['salary_amount']],
        ], fn (): array => DB::transaction(function () use ($company, $replacement, $original, $data, $actor, $returnDate, $lastDay): array {
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
                // The person returns to the position as it is run now: the shift of the replacement unless HR chooses another one.
                'shift' => array_key_exists('shift', $data) ? $data['shift'] : $replacement->shift?->value,
                'start_date' => $returnDate->toDateString(),
                'salary_amount' => $data['salary_amount'],
                'salary_currency' => strtoupper((string) $data['salary_currency']),
                'salary_frequency' => $data['salary_frequency'],
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
                    'shift' => $returned->shift?->value,
                    'previous_salary' => ['amount' => (string) $original->salary_amount, 'currency' => $original->salary_currency],
                    'confirmed_salary' => ['amount' => number_format((float) $data['salary_amount'], 2, '.', ''), 'currency' => strtoupper((string) $data['salary_currency'])],
                ],
            ));

            return ['replacement' => $replacement->refresh()->load(['employee', 'position']), 'returned' => $returned->load(['employee', 'position'])];
        }));
    }
}
