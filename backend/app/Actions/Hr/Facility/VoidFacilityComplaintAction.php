<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FacilityComplaint;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Withdraws a complaint registered by mistake. It stays in the history (with who voided it and why) but no total counts it. */
class VoidFacilityComplaintAction
{
    public function handle(FacilityComplaint $complaint, string $reason, Admin $actor): FacilityComplaint
    {
        if ($complaint->voided_at !== null) {
            throw new HttpResponseException(ApiResponse::error('This complaint is already voided.', 'FACILITY_COMPLAINT_VOIDED', 422));
        }

        $complaint->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => trim($reason)]);
        $complaint->events()->create(['admin_id' => $actor->id, 'event' => 'voided', 'note' => trim($reason)]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Complaint #{$complaint->id} voided.",
            entityType: FacilityComplaint::class,
            entityId: $complaint->id,
            metadata: ['void' => true, 'reason' => trim($reason)],
        ));

        return $complaint->load('clientCompany', 'events.admin', 'reporterEmployee');
    }
}
