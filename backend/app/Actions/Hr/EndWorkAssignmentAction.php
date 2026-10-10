<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;

class EndWorkAssignmentAction
{
    public function handle(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
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
