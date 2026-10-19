<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Actions\Hr\EndWorkAssignmentAction;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use App\Support\Hr\PositionSuccession;
use Illuminate\Support\Facades\DB;

/**
 * Replace Employee: the original employee's assignment ends on its last day, and the replacement's assignment starts after it and
 * is linked to it (replaces_assignment_id). Both assignments - and everything paid under them - are kept. In ONE transaction: if
 * anything fails, nothing changes.
 *
 * The replacement is explicitly Permanent or Replacement (never inferred). The original's last paid day defaults to the day before
 * the replacement date; a gap between them is allowed and reported, an overlap (a day paid to two people) is refused by the
 * existing succession rule. A change reaching into an approved or finalized payroll month is refused.
 */
class ReplaceCompanyEmployeeAction
{
    public function __construct(
        private readonly EndWorkAssignmentAction $end,
        private readonly CreateWorkAssignmentAction $create,
        private readonly PayrollPeriodLock $lock = new PayrollPeriodLock,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{original: EmployeeWorkAssignment, replacement: EmployeeWorkAssignment}
     */
    public function handle(ClientCompany $company, EmployeeWorkAssignment $original, array $data, Admin $actor): array
    {
        CompanyAssignmentGuard::requireOpen($original);

        $replacementDate = CompanyAssignmentGuard::date($data['replacement_date']);
        $lastDay = isset($data['original_last_day']) ? CompanyAssignmentGuard::date($data['original_last_day']) : $replacementDate->subDay();
        $replacement = Employee::withTrashed()->findOrFail((int) $data['replacement_employee_id']);

        if ($replacement->id === $original->employee_id) {
            CompanyAssignmentGuard::fail('An employee cannot replace themselves. Choose a different employee.', 'REPLACEMENT_SAME_EMPLOYEE');
        }

        CompanyAssignmentGuard::requireAssignable($replacement);

        if ($lastDay->lt(CompanyAssignmentGuard::date($original->start_date->toDateString()))) {
            CompanyAssignmentGuard::fail("The original employee's last day ({$lastDay->toDateString()}) is before the assignment started ({$original->start_date->toDateString()}). Choose a later replacement date.", 'REPLACEMENT_BEFORE_ASSIGNMENT_START');
        }

        PositionSuccession::assertNoOverlap($lastDay, $replacementDate);

        $this->lock->assertAssignmentDateChangeAllowed($lastDay->addDay()->toDateString());

        $original->loadMissing('employee', 'position');

        return DB::transaction(function () use ($company, $original, $data, $actor, $replacementDate, $lastDay, $replacement): array {
            $reason = trim((string) ($data['reason'] ?? ''));

            $this->end->handle($original, [
                'end_date' => $lastDay->toDateString(),
                'note' => "Replaced by {$replacement->full_name} ({$replacement->employee_number}) from {$replacementDate->toDateString()}.".($reason !== '' ? " Reason: {$reason}" : ''),
            ], $actor);

            $new = $this->create->handle($replacement, [
                'client_company_id' => $company->id,
                'work_location_id' => $original->work_location_id,
                'replaces_assignment_id' => $original->id,
                'position_id' => $data['position_id'] ?? $original->position_id,
                'assignment_type' => $data['assignment_type'],
                'start_date' => $replacementDate->toDateString(),
                'salary_amount' => $data['salary_amount'],
                'salary_currency' => strtoupper((string) $data['salary_currency']),
                'salary_frequency' => $data['salary_frequency'],
                'notes' => $data['notes'] ?? null,
            ], $actor);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "'{$original->employee?->full_name}' ({$original->employee?->employee_number}) replaced by '{$replacement->full_name}' ({$replacement->employee_number}) at '{$company->name}'.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $original->id,
                metadata: [
                    'event' => 'replaced',
                    'company_id' => $company->id,
                    'position_id' => $original->position_id,
                    'original_assignment_id' => $original->id,
                    'original_employee_id' => $original->employee_id,
                    'original_last_day' => $lastDay->toDateString(),
                    'replacement_assignment_id' => $new->id,
                    'replacement_employee_id' => $replacement->id,
                    'replacement_start_date' => $replacementDate->toDateString(),
                    'assignment_type' => $new->assignment_type->value,
                    'gap_days' => PositionSuccession::gapDays($lastDay, $replacementDate),
                    'reason' => $reason !== '' ? $reason : null,
                ],
            ));

            return ['original' => $original->refresh()->load(['employee', 'position']), 'replacement' => $new->load(['employee', 'position'])];
        });
    }
}
