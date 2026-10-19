<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\AssignmentChain;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;

class EndWorkAssignmentAction
{
    public function __construct(private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection) {}

    /**
     * Moving the last paid day changes the pay of every month after it. When that reaches an approved or finalized month it is a Super
     * Admin correction (reason required) - see ClosedPayrollCorrection.
     */
    public function handle(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        $old = $assignment->end_date?->toDateString();
        $new = CarbonImmutable::parse($data['end_date'])->toDateString();

        return $this->corrections->run(
            $actor,
            'stopped',
            AssignmentChain::employeeIds($assignment),
            CarbonImmutable::parse($old === null ? $new : min($old, $new))->addDay()->toDateString(),
            $data['correction_reason'] ?? null,
            ['assignment_id' => $assignment->id, 'old' => ['end_date' => $old], 'new' => ['end_date' => $new]],
            fn (): EmployeeWorkAssignment => $this->end($assignment, $data, $actor),
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
