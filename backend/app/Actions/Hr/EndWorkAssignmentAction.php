<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeWorkAssignment;

class EndWorkAssignmentAction
{
    public function handle(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
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

        return $assignment->load(['workLocation.clientCompany', 'position']);
    }
}
