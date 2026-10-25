<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FacilityInspection;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws an inspection that was recorded by mistake. It is never deleted: it stays in the history with who voided it and why, and no status or
 * report counts it. A Deep Cleaning completion recorded inside it is withdrawn with it (kept, but never counted again).
 */
class VoidFacilityInspectionAction
{
    public function __construct(private readonly RecordFacilityDeepCleaningAction $deepCleaning) {}

    public function handle(FacilityInspection $inspection, string $reason, Admin $actor): FacilityInspection
    {
        if ($inspection->voided_at !== null) {
            throw new HttpResponseException(ApiResponse::error('This inspection is already voided.', 'FACILITY_INSPECTION_VOIDED', 422));
        }

        DB::transaction(function () use ($inspection, $reason, $actor): void {
            $inspection->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => trim($reason)]);
            $withdrew = $this->deepCleaning->withdrawWithInspection($inspection, trim($reason), $actor);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Facility inspection #{$inspection->id} voided.",
                entityType: FacilityInspection::class,
                entityId: $inspection->id,
                metadata: ['void' => true, 'reason' => trim($reason), 'deep_cleaning_withdrawn' => $withdrew],
            ));
        });

        return $inspection;
    }
}
