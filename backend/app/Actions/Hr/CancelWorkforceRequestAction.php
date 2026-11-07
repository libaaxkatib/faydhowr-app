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
 *
 * Owner decision B-Q4 (2026-11-06): a FULFILLED request may be cancelled too. It is a status change only: the request's own data, every match, the
 * matched employees and their assignments stay exactly as they were (a match never created an assignment, and nothing here ends or edits one). The audit
 * entry records what the request was before (its status and how many matches it keeps), so the trail shows that a fulfilled request was cancelled.
 */
class CancelWorkforceRequestAction
{
    public function handle(WorkforceRequest $request, Admin $actor): WorkforceRequest
    {
        $from = $request->status;
        $matches = $request->matches()->count();

        $request->update(['status' => WorkforceRequestStatus::Cancelled]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Workforce request #{$request->id} cancelled (it was {$from->value}; {$matches} match(es) kept).",
            entityType: WorkforceRequest::class,
            entityId: $request->id,
            metadata: ['event' => 'workforce_request_cancelled', 'from_status' => $from->value, 'matches_kept' => $matches],
        ));

        return $request->load('workLocation.clientCompany', 'clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee');
    }
}
