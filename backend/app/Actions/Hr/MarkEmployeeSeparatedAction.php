<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeSeparationReason;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeTransitionGuard;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 3 (Workforce Operations): replaces the
 * bare status-flip the "Mark Inactive" button used to perform - every
 * transition to Inactive now captures a reason, mirroring UpdateEmployeeStatusAction's
 * status-history + waiting_since-clearing behavior but adding the structured
 * separation record. UpdateEmployeeStatusAction itself is untouched and still
 * drives every other (forward) transition.
 */
class MarkEmployeeSeparatedAction
{
    public function __construct(
        private readonly CloseAssignmentsOnSeparationAction $closeAssignments,
        private readonly EmployeeTransitionGuard $guard,
    ) {}

    public function handle(
        Employee $employee,
        EmployeeSeparationReason $reason,
        string $separationDate,
        bool $rehireEligible,
        ?string $notes,
        Admin $actor,
        array $successors = [],
        ?string $correctionReason = null,
        bool $confirmReplacementContinues = false,
    ): Employee {
        if ($employee->status === EmployeeStatus::Inactive) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' is already inactive.",
                    'EMPLOYEE_ALREADY_INACTIVE',
                    422,
                ),
            );
        }

        return DB::transaction(function () use ($employee, $reason, $separationDate, $rehireEligible, $notes, $actor, $successors, $correctionReason, $confirmReplacementContinues) {
            $from = $employee->status;

            $this->guard->authorize($from, EmployeeStatus::Inactive, EmployeeTransitionGuard::CONTEXT_SEPARATION, $actor);

            $employee->update([
                'status' => EmployeeStatus::Inactive,
                'waiting_since' => $from === EmployeeStatus::Waiting ? null : $employee->waiting_since,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'from_status' => $from,
                'to_status' => EmployeeStatus::Inactive,
                'changed_by' => $actor->id,
                'note' => "Separated ({$reason->label()}).",
            ]);

            EmployeeSeparation::query()->create([
                'employee_id' => $employee->id,
                'reason' => $reason,
                'separation_date' => $separationDate,
                'rehire_eligible' => $rehireEligible,
                'notes' => $notes,
                'separated_by' => $actor->id,
            ]);

            // Approved rule: the separation also closes this employee's open assignments
            // (inside the same transaction, so a failure undoes the whole separation).
            // Every open Permanent assignment needs its replacement choice (docs/HR_PAYROLL_RULES.md N1); `successors` is keyed by assignment id.
            // A separation dated inside an approved or finalized payroll month changes what that month should have paid: like every other assignment change it is a
            // recorded payroll correction (a Super Admin or an HR Manager, with a reason) and never a silent rewrite.
            $successorEmployees = array_values(array_filter(array_map(fn ($choice): int => (int) ($choice['employee_id'] ?? 0), array_values($successors))));
            $closed = (new ClosedPayrollCorrection)->run(
                $actor,
                'separated',
                [$employee->id, ...$successorEmployees],
                CarbonImmutable::parse($separationDate)->addDay()->toDateString(),
                $correctionReason,
                ['assignment_id' => null, 'old' => ['employee_status' => $from->value], 'new' => ['employee_status' => EmployeeStatus::Inactive->value, 'separation_date' => $separationDate]],
                fn (): array => $this->closeAssignments->handle($employee, $separationDate, $actor, $successors, $correctionReason, $confirmReplacementContinues),
            );

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$employee->full_name}' separated ({$reason->label()}).",
                entityType: Employee::class,
                entityId: $employee->id,
                metadata: [
                    'reason' => $reason->value,
                    'separation_date' => $separationDate,
                    'assignments_closed' => $closed['assignments'],
                    'replacements_ended' => $closed['replacements_ended'],
                    'finalized_payroll_after_separation' => $closed['finalized_payroll_after_separation'],
                ],
            ));

            return $employee->load('category', 'department', 'position', 'statusHistories.changedBy', 'separations.separatedBy', 'latestSeparation.separatedBy');
        });
    }
}
