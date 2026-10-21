<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FacilityInspection;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Withdraws an inspection that was recorded by mistake. It is never deleted: it stays in the history with who voided it and why, and no status or report counts it. */
class VoidFacilityInspectionAction
{
    public function handle(FacilityInspection $inspection, string $reason, Admin $actor): FacilityInspection
    {
        if ($inspection->voided_at !== null) {
            throw new HttpResponseException(ApiResponse::error('This inspection is already voided.', 'FACILITY_INSPECTION_VOIDED', 422));
        }

        $inspection->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => trim($reason)]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Facility inspection #{$inspection->id} voided.",
            entityType: FacilityInspection::class,
            entityId: $inspection->id,
            metadata: ['void' => true, 'reason' => trim($reason)],
        ));

        return $inspection;
    }
}
