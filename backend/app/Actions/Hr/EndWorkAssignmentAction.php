<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentSuccession;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\AssignmentChain;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\PermanentSuccession;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class EndWorkAssignmentAction
{
    public function __construct(private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection) {}

    /**
     * Moving the last paid day changes the pay of every month after it. When that reaches an approved or finalized month it is a Super
     * Admin correction (reason required) - see ClosedPayrollCorrection.
     */
    /**
     * Ending an OPEN PERMANENT assignment needs the replacement choice (`successor`: type temporary|waiting_list, employee_id, and the salary for a
     * Waiting List person) - docs/HR_PAYROLL_RULES.md N1-N3. It is validated before anything is written and carried out in the same transaction as
     * the ending. Replace Employee passes `succession_handled` because it names its successor itself; a Replacement-type assignment needs no choice.
     */
    public function handle(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        $old = $assignment->end_date?->toDateString();
        $new = CarbonImmutable::parse($data['end_date'])->toDateString();

        // The last paid day can never be earlier than the first day (the same rule as Stop). A correction reason does not bypass it.
        if ($assignment->start_date !== null && $new < $assignment->start_date->toDateString()) {
            throw new HttpResponseException(ApiResponse::error(
                "The last day ({$new}) is before the assignment started ({$assignment->start_date->toDateString()}).",
                'END_BEFORE_ASSIGNMENT_START',
                422,
                ['end_date' => ['The last day cannot be before the assignment started.']],
            ));
        }

        // A Replacement covering an away holder leaves only through Return or a swap (which pass `cycle_managed` and record the cycle's events).
        if (($data['cycle_managed'] ?? false) !== true) {
            AbsenceCycles::assertNotBound($assignment, 'Ending this assignment');
        }

        $plan = ($data['succession_handled'] ?? false) === true
            ? null
            : PermanentSuccession::demand($assignment, $data['successor'] ?? null, CarbonImmutable::parse($new)->startOfDay(), optional: true);

        // The holder may be ended while away (a Replacement may be working). That never ends the Replacement; HR confirms it first (AbsenceCycles).
        $absence = AbsenceCycles::assertTerminationAllowed($assignment, CarbonImmutable::parse($new)->startOfDay(), ($data['confirm_replacement_continues'] ?? false) === true);

        return $this->corrections->run(
            $actor,
            'stopped',
            // The chosen replacement's pay is part of the change too (a start inside an approved or finalized month is a correction).
            [...AssignmentChain::employeeIds($assignment), ...($plan === null ? [] : [$plan['employee']->id])],
            CarbonImmutable::parse($old === null ? $new : min($old, $new))->addDay()->toDateString(),
            $data['correction_reason'] ?? null,
            ['assignment_id' => $assignment->id, 'old' => ['end_date' => $old], 'new' => ['end_date' => $new]],
            fn (): EmployeeWorkAssignment => DB::transaction(function () use ($assignment, $data, $actor, $plan, $absence, $new): EmployeeWorkAssignment {
                $ended = $this->end($assignment, $data, $actor);

                if ($absence !== null) {
                    AbsenceCycles::recordTermination($absence, CarbonImmutable::parse($new)->startOfDay(), $actor);
                }

                if ($plan !== null) {
                    PermanentSuccession::complete($assignment->refresh(), $plan, $actor, $data['succession_trigger'] ?? AssignmentSuccession::TRIGGER_END, $data['correction_reason'] ?? null);
                }

                return $ended;
            }),
        );
    }

    private function end(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        // The last paid day must stay before any successor's first day (no calendar day paid twice).
        PositionSuccession::assertEndKeepsSuccessorClear($assignment, CarbonImmutable::parse($data['end_date']));

        $assignment->update([
            'end_date' => $data['end_date'],
            'status' => WorkAssignmentStatus::Ended,
            'notes' => $data['note'] ?? $assignment->notes,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Work assignment #{$assignment->id} ended.",
            entityType: EmployeeWorkAssignment::class,
            entityId: $assignment->id,
        ));

        return $assignment->load(['workLocation.clientCompany', 'clientCompany', 'position']);
    }
}
