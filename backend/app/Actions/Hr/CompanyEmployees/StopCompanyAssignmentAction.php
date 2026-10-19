<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Actions\Hr\EndWorkAssignmentAction;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\AssignmentChain;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use Illuminate\Support\Facades\DB;

/**
 * Stop Employee: ends THIS company assignment on a given last day, with a reason. It does NOT change the employee's HR status or
 * employment - ending employment altogether is the separate Separation action on the employee record (which closes assignments
 * itself). The assignment, its salary history, attendance and payroll all stay; the position can be given to another registered
 * employee afterwards. Nothing is deleted. A last day that reaches into an approved or finalized payroll month is a Super Admin
 * correction (reason required) - see ClosedPayrollCorrection.
 */
class StopCompanyAssignmentAction
{
    public function __construct(
        private readonly EndWorkAssignmentAction $end,
        private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection,
    ) {}

    /** @param  array{end_date: string, reason: string}  $data */
    public function handle(ClientCompany $company, EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        CompanyAssignmentGuard::requireOpen($assignment);

        $lastDay = CompanyAssignmentGuard::date($data['end_date']);

        if ($lastDay->lt(CompanyAssignmentGuard::date($assignment->start_date->toDateString()))) {
            CompanyAssignmentGuard::fail("The last day ({$lastDay->toDateString()}) is before the assignment started ({$assignment->start_date->toDateString()}).", 'STOP_BEFORE_ASSIGNMENT_START');
        }

        $assignment->loadMissing('employee');

        return $this->corrections->run($actor, 'stopped', AssignmentChain::employeeIds($assignment), $lastDay->addDay()->toDateString(), $data['correction_reason'] ?? null, [
            'assignment_id' => $assignment->id,
            'old' => ['end_date' => $assignment->end_date?->toDateString(), 'status' => $assignment->status->value],
            'new' => ['end_date' => $lastDay->toDateString(), 'status' => 'ended'],
        ], fn (): EmployeeWorkAssignment => DB::transaction(function () use ($company, $assignment, $data, $actor, $lastDay): EmployeeWorkAssignment {
            $reason = trim($data['reason']);

            $this->end->handle($assignment, ['end_date' => $lastDay->toDateString(), 'note' => "Stopped: {$reason}"], $actor);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Assignment of '{$assignment->employee?->full_name}' ({$assignment->employee?->employee_number}) at '{$company->name}' stopped; last day {$lastDay->toDateString()}.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $assignment->id,
                metadata: [
                    'event' => 'stopped',
                    'company_id' => $company->id,
                    'employee_id' => $assignment->employee_id,
                    'last_day' => $lastDay->toDateString(),
                    'reason' => $reason,
                    'scope' => 'company_assignment_only',
                    'employee_status_unchanged' => $assignment->employee?->status->value,
                ],
            ));

            return $assignment->refresh()->load(['employee', 'position']);
        }));
    }
}
