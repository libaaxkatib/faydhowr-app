<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\AssignmentChain;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use App\Support\Hr\ConcurrentAssignmentGuard;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\PermanentSuccession;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Undo for "Stop employee / End assignment": puts an ENDED assignment back to Active with no end date, for an assignment that was ended by mistake. Nothing is
 * deleted or recreated - it is the same assignment with the same salary history, so the days after the old last day are paid again by the ordinary payroll.
 *
 * Refused (nothing is changed) when it would clash with what happened since: the company is closed (reopen the company first), the position already has a
 * successor (it was filled or replaced), the employee is no longer assignable, or the employee would overlap another assignment the concurrent-assignment
 * rule does not allow. If the restored days reach an approved or finalized payroll month it is a recorded payroll correction exactly like stopping was
 * (ClosedPayrollCorrection: a Super Admin or an HR Manager, with a reason); otherwise it needs only the reason of the reopening.
 */
class ReopenEndedAssignmentAction
{
    public function __construct(private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection) {}

    /** @param  array{reason: string, correction_reason?: ?string}  $data */
    public function handle(ClientCompany $company, EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        if ($assignment->status !== WorkAssignmentStatus::Ended) {
            CompanyAssignmentGuard::fail('Only an ended assignment can be reopened. This one is '.$assignment->status->value.'.', 'ASSIGNMENT_NOT_ENDED');
        }

        $assignment->loadMissing('employee');
        $employee = $assignment->employee;

        if ($company->status->value !== 'active') {
            CompanyAssignmentGuard::fail("{$company->name} is closed. Reopen the company first, then reopen this assignment.", 'COMPANY_CLOSED');
        }

        CompanyAssignmentGuard::requireAssignable($employee);

        if (PermanentSuccession::recorded($assignment) !== null || PositionSuccession::successorsOf($assignment)->exists()) {
            CompanyAssignmentGuard::fail('This position was given to someone else after the assignment ended, so it cannot be reopened. Correct the other assignment first.', 'ASSIGNMENT_POSITION_FILLED');
        }

        $oldEnd = $assignment->end_date?->toDateString();
        $start = CarbonImmutable::parse($assignment->start_date->toDateString())->startOfDay();

        ConcurrentAssignmentGuard::assertAllowed($employee, $assignment->client_company_id === null ? null : (int) $assignment->client_company_id, $start, null, $assignment->id);

        $reason = trim($data['reason']);
        $earliest = $oldEnd === null ? $start->toDateString() : CarbonImmutable::parse($oldEnd)->addDay()->toDateString();

        return $this->corrections->run($actor, 'assignment_reopened', AssignmentChain::employeeIds($assignment), $earliest, $data['correction_reason'] ?? null, [
            'assignment_id' => $assignment->id,
            'old' => ['end_date' => $oldEnd, 'status' => 'ended'],
            'new' => ['end_date' => null, 'status' => 'active'],
        ], fn (): EmployeeWorkAssignment => DB::transaction(function () use ($company, $assignment, $actor, $oldEnd, $reason, $employee): EmployeeWorkAssignment {
            $assignment->update([
                'status' => WorkAssignmentStatus::Active,
                'end_date' => null,
                'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '')."Reopened: {$reason}"),
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Assignment of '{$employee?->full_name}' ({$employee?->employee_number}) at '{$company->name}' reopened (it had ended".($oldEnd === null ? '' : " on {$oldEnd}")."): {$reason}",
                entityType: EmployeeWorkAssignment::class,
                entityId: $assignment->id,
                metadata: [
                    'event' => 'assignment_reopened',
                    'company_id' => $company->id,
                    'employee_id' => $assignment->employee_id,
                    'previous_status' => 'ended',
                    'previous_end_date' => $oldEnd,
                    'new_status' => 'active',
                    'reason' => $reason,
                    'reopened_by_role' => $actor->role->value,
                    'scope' => 'company_assignment_only',
                    'employee_status_unchanged' => $employee?->status->value,
                ],
            ));

            return $assignment->refresh()->load(['employee', 'position']);
        }));
    }
}
