<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeWorkAssignment;

/**
 * Edits salary/position/dates/notes on an existing assignment — salary must
 * remain editable by authorized HR/management users. Never changes
 * employee_id or work_location_id; reassigning to a different location is a
 * new assignment (via CreateWorkAssignmentAction), not a mutation of this one,
 * so history is never silently rewritten.
 */
class UpdateWorkAssignmentAction
{
    public function handle(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        $assignment->update([
            'position_id' => $data['position_id'] ?? $assignment->position_id,
            'start_date' => $data['start_date'] ?? $assignment->start_date,
            'end_date' => $data['end_date'] ?? $assignment->end_date,
            'salary_amount' => $data['salary_amount'] ?? $assignment->salary_amount,
            'salary_currency' => $data['salary_currency'] ?? $assignment->salary_currency,
            'salary_frequency' => $data['salary_frequency'] ?? $assignment->salary_frequency,
            'notes' => $data['notes'] ?? $assignment->notes,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Work assignment #{$assignment->id} updated.",
            entityType: EmployeeWorkAssignment::class,
            entityId: $assignment->id,
        ));

        return $assignment->load(['workLocation.clientCompany', 'position']);
    }
}
