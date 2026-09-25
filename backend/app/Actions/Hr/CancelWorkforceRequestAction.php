<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\WorkforceRequestStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkforceRequest;

/**
 * Cancelling a request never touches its existing workforce_request_matches -
 * confirmed matches are permanent history, same principle as every other
 * pipeline history table in this app.
 */
class CancelWorkforceRequestAction
{
    public function handle(WorkforceRequest $request, Admin $actor): WorkforceRequest
    {
        $request->update(['status' => WorkforceRequestStatus::Cancelled]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Workforce request #{$request->id} cancelled.",
            entityType: WorkforceRequest::class,
            entityId: $request->id,
        ));

        return $request->load('workLocation.clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee');
    }
}
