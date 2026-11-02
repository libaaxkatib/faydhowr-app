<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Actions\Hr\ChangeAssignmentSalaryAction;
use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Actions\Hr\EndWorkAssignmentAction;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Enums\WorkAssignmentStatus;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Support\Facades\DB;

/**
 * Return Original Employee. The call names the CURRENT Replacement assignment. In one transaction it ends that assignment on the
 * day before the return date. Nothing is deleted or rewritten: every payroll and attendance record stays as it was.
 *
 *  - When the original assignment was kept open during the absence (every Replacement recorded through Replace Employee since the replacement
 *    cycles), the SAME assignment simply resumes: the absence is closed with the return date, the cycle's events are written, and NO new row is
 *    created. The salary is not asked for (it did not change); a salary change goes through the normal salary change of that assignment.
 *  - An older Replacement, whose original assignment had already been ended at the replacement, keeps the former behaviour: the original employee
 *    gets a NEW Permanent assignment starting on the return date, linked to the replacement's. Reopening that history is a separate, approved
 *    reconciliation.
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

        $absence = AbsenceCycles::ofReplacement($replacement);

        if ($absence !== null) {
            return $this->resume($company, $replacement, $original, $absence, $data, $actor);
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

    /** The checks that decide whether a holder can still come back: they run before the work and again under the lock. */
    private function assertCanResume(AssignmentAbsence $absence, EmployeeWorkAssignment $original): void
    {
        if ($absence->terminated_on !== null) {
            CompanyAssignmentGuard::fail("The original assignment was ended on {$absence->terminated_on->toDateString()} while the employee was away, so there is nothing to return to. Use Replace Employee, or make the Replacement Permanent.", 'RETURN_ORIGINAL_TERMINATED');
        }

        if ($absence->returned_on !== null || $original->status !== WorkAssignmentStatus::Active) {
            CompanyAssignmentGuard::fail('The original employee has already returned to this assignment.', 'ABSENCE_ALREADY_CLOSED');
        }
    }

    /**
     * The original assignment was kept open: close the absence and end the Replacement the day before. The original comes back under its own id.
     *
     * @param  array<string, mixed>  $data
     * @return array{replacement: EmployeeWorkAssignment, returned: EmployeeWorkAssignment, absence: AssignmentAbsence}
     */
    private function resume(ClientCompany $company, EmployeeWorkAssignment $replacement, EmployeeWorkAssignment $original, AssignmentAbsence $absence, array $data, Admin $actor): array
    {
        $this->assertCanResume($absence, $original);

        CompanyAssignmentGuard::requireAssignable($original->employee);

        $returnDate = CompanyAssignmentGuard::date($data['return_date']);
        $lastDay = $returnDate->subDay();

        if ($lastDay->lt(CompanyAssignmentGuard::date($replacement->start_date->toDateString()))) {
            CompanyAssignmentGuard::fail("The replacement started on {$replacement->start_date->toDateString()}; the return date must be after that day.", 'RETURN_BEFORE_REPLACEMENT_STARTED', ['return_date' => ['The return date must be after the day the replacement started.']]);
        }

        // The salary of a returning employee is never assumed unchanged: HR confirms it (the assignment's current one is the proposal) or edits it.
        foreach (['salary_amount', 'salary_currency', 'salary_frequency'] as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                CompanyAssignmentGuard::fail('Confirm or edit the salary of the returning employee before saving; it is not assumed to be unchanged.', 'RETURN_SALARY_REQUIRED', [$field => ['The salary must be confirmed.']]);
            }
        }

        $confirmed = ['amount' => number_format((float) $data['salary_amount'], 2, '.', ''), 'currency' => strtoupper((string) $data['salary_currency']), 'frequency' => (string) $data['salary_frequency']];
        $salaryChanged = PayrollMoney::toCents($confirmed['amount']) !== PayrollMoney::toCents($original->salary_amount)
            || $confirmed['currency'] !== strtoupper((string) $original->salary_currency)
            || $confirmed['frequency'] !== $original->salary_frequency->value;

        $replacement->loadMissing('employee');

        return $this->corrections->run($actor, 'returned', [$replacement->employee_id, $original->employee_id], $returnDate->toDateString(), $data['correction_reason'] ?? null, [
            'assignment_id' => $replacement->id,
            'old' => ['replacement_assignment_id' => $replacement->id, 'end_date' => $replacement->end_date?->toDateString()],
            'new' => ['return_date' => $returnDate->toDateString(), 'replacement_last_day' => $lastDay->toDateString(), 'original_assignment_id' => $original->id],
        ], fn (): array => DB::transaction(function () use ($company, $replacement, $original, $absence, $actor, $returnDate, $lastDay, $confirmed, $salaryChanged): array {
            // Two people pressing Return (or Return racing the termination of the original) must not both succeed: lock the original and the absence,
            // then look again at what the checks above saw.
            $lockedOriginal = EmployeeWorkAssignment::query()->lockForUpdate()->findOrFail($original->id);
            $absence = AssignmentAbsence::query()->lockForUpdate()->findOrFail($absence->id);
            $this->assertCanResume($absence, $lockedOriginal);
            CompanyAssignmentGuard::requireOpen($replacement->refresh());

            $previousSalary = ['amount' => (string) $original->salary_amount, 'currency' => $original->salary_currency];

            $this->end->handle($replacement, [
                'end_date' => $lastDay->toDateString(),
                'note' => "Ended because {$original->employee->full_name} ({$original->employee->employee_number}) returned on {$returnDate->toDateString()}.",
                'cycle_managed' => true,
            ], $actor);

            // An edited salary is an ordinary salary change of THIS assignment from the return date (history kept; a Replacement's inherited salary follows the
            // existing rule of ChangeAssignmentSalaryAction).
            if ($salaryChanged) {
                app(ChangeAssignmentSalaryAction::class)->handle($original, $confirmed['amount'], $confirmed['currency'], $confirmed['frequency'], $returnDate->toDateString(), 'Confirmed on return', $actor);
            }

            $absence->update(['returned_on' => $returnDate->toDateString()]);
            AbsenceCycles::event($absence, AssignmentCycleEvent::REPLACEMENT_ENDED, $lastDay, $actor, $replacement->id);
            AbsenceCycles::event($absence, AssignmentCycleEvent::RETURNED, $returnDate, $actor, $replacement->id);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "'{$original->employee->full_name}' ({$original->employee->employee_number}) returned to '{$company->name}' on the same assignment #{$original->id}; the replacement '{$replacement->employee?->full_name}' ended on {$lastDay->toDateString()}.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $original->id,
                metadata: [
                    'event' => 'returned',
                    'company_id' => $company->id,
                    'original_employee_id' => $original->employee_id,
                    'original_assignment_id' => $original->id,
                    'replacement_assignment_id' => $replacement->id,
                    'replacement_employee_id' => $replacement->employee_id,
                    'replacement_last_day' => $lastDay->toDateString(),
                    'returned_assignment_id' => $original->id,
                    'return_date' => $returnDate->toDateString(),
                    'absence_id' => $absence->id,
                    'new_assignment_created' => false,
                    'previous_salary' => $previousSalary,
                    'confirmed_salary' => ['amount' => $confirmed['amount'], 'currency' => $confirmed['currency']],
                    'salary_changed' => $salaryChanged,
                ],
            ));

            return ['replacement' => $replacement->refresh()->load(['employee', 'position']), 'returned' => $original->refresh()->load(['employee', 'position']), 'absence' => $absence->refresh()];
        }));
    }
}
