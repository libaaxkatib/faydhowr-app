<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Enums\FacilityComplaintStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FacilityComplaint;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY way a complaint's status changes. Each step is explicit and written to the complaint's history with who, when and the note.
 *
 *  - "answered" needs a recorded response (a complaint cannot be answered without one) and does NOT make it resolved;
 *  - "resolved" needs a note saying how it was resolved; the resolution date defaults to today;
 *  - a resolved complaint can only be reopened, with a note.
 */
class TransitionFacilityComplaintAction
{
    public function handle(FacilityComplaint $complaint, FacilityComplaintStatus $to, ?string $note, ?string $resolvedOn, Admin $actor): FacilityComplaint
    {
        if ($complaint->voided_at !== null) {
            throw new HttpResponseException(ApiResponse::error('A voided complaint cannot change status.', 'FACILITY_COMPLAINT_VOIDED', 422));
        }

        $from = $complaint->status;
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! in_array($to, $from->nextAllowed(), true)) {
            throw new HttpResponseException(ApiResponse::error(
                "A complaint that is \"{$from->label()}\" cannot move to \"{$to->label()}\".",
                'COMPLAINT_TRANSITION_NOT_ALLOWED',
                422,
                ['from' => $from->value, 'allowed' => array_map(fn (FacilityComplaintStatus $s): string => $s->value, $from->nextAllowed())],
            ));
        }

        if ($to === FacilityComplaintStatus::Answered && trim((string) $complaint->response) === '') {
            throw new HttpResponseException(ApiResponse::error('Record the response first: a complaint cannot be marked answered without one.', 'COMPLAINT_RESPONSE_REQUIRED', 422));
        }

        if (($to === FacilityComplaintStatus::Resolved || $from === FacilityComplaintStatus::Resolved) && ($note === null || mb_strlen($note) < 5)) {
            throw new HttpResponseException(ApiResponse::error(
                $to === FacilityComplaintStatus::Resolved ? 'Say how the complaint was resolved (at least a few words).' : 'Say why the resolved complaint is reopened (at least a few words).',
                'COMPLAINT_NOTE_REQUIRED',
                422,
            ));
        }

        return DB::transaction(function () use ($complaint, $from, $to, $note, $resolvedOn, $actor): FacilityComplaint {
            $changes = ['status' => $to->value];

            if ($to === FacilityComplaintStatus::Answered && $complaint->responded_on === null) {
                $changes['responded_on'] = now()->toDateString();
            }
            if ($to === FacilityComplaintStatus::Resolved) {
                $changes['resolved_on'] = $resolvedOn ?? now()->toDateString();
            }
            if ($from === FacilityComplaintStatus::Resolved) {
                $changes['resolved_on'] = null;
            }

            $complaint->update($changes);
            $complaint->events()->create(['admin_id' => $actor->id, 'event' => 'status_changed', 'from_status' => $from->value, 'to_status' => $to->value, 'note' => $note]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Complaint #{$complaint->id} moved from {$from->label()} to {$to->label()}.",
                entityType: FacilityComplaint::class,
                entityId: $complaint->id,
                metadata: ['from' => $from->value, 'to' => $to->value],
            ));

            return $complaint->load('clientCompany', 'events.admin', 'reporterEmployee');
        });
    }
}
