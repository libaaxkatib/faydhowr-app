<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkforceRequest;

/**
 * Edits criteria fields only - status is never hand-edited here, it's derived
 * from confirmed matches (ConfirmWorkforceRequestMatchAction) and cancellation
 * (CancelWorkforceRequestAction), so it can never drift out of sync.
 */
class UpdateWorkforceRequestAction
{
    public function handle(WorkforceRequest $request, array $data, Admin $actor): WorkforceRequest
    {
        $request->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Workforce request #{$request->id} updated.",
            entityType: WorkforceRequest::class,
            entityId: $request->id,
        ));

        return $request->load('workLocation.clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee');
    }
}
