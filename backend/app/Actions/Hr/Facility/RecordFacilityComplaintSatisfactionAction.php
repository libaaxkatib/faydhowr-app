<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Enums\FacilitySatisfaction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FacilityComplaint;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Records satisfaction ONLY from explicit documented feedback: the person must say what the feedback was (evidence) and nothing is
 * ever inferred from a status, a response or a resolution. Recording it does not change the status. A mistaken entry can be cleared, with a
 * note; both steps stay in the complaint's history.
 */
class RecordFacilityComplaintSatisfactionAction
{
    public function handle(FacilityComplaint $complaint, ?FacilitySatisfaction $satisfaction, ?string $evidence, ?string $recordedOn, Admin $actor): FacilityComplaint
    {
        if ($complaint->voided_at !== null) {
            throw new HttpResponseException(ApiResponse::error('A voided complaint cannot be changed.', 'FACILITY_COMPLAINT_VOIDED', 422));
        }

        $evidence = $evidence === null ? null : trim($evidence);

        if ($evidence === null || mb_strlen($evidence) < 10) {
            throw new HttpResponseException(ApiResponse::error(
                $satisfaction === null ? 'Say why the recorded satisfaction is being cleared.' : 'Describe the documented feedback (who said it, how and when).',
                'SATISFACTION_EVIDENCE_REQUIRED',
                422,
            ));
        }

        return DB::transaction(function () use ($complaint, $satisfaction, $evidence, $recordedOn, $actor): FacilityComplaint {
            $before = $complaint->satisfaction?->value;

            $complaint->update($satisfaction === null
                ? ['satisfaction' => null, 'satisfaction_evidence' => null, 'satisfaction_recorded_on' => null]
                : ['satisfaction' => $satisfaction->value, 'satisfaction_evidence' => $evidence, 'satisfaction_recorded_on' => $recordedOn ?? now()->toDateString()]);

            $complaint->events()->create([
                'admin_id' => $actor->id,
                'event' => $satisfaction === null ? 'satisfaction_cleared' : 'satisfaction_recorded',
                'note' => ($satisfaction === null ? 'Cleared (was '.($before ?? 'none').'): ' : ucfirst(str_replace('_', ' ', $satisfaction->value)).': ').$evidence,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Complaint #{$complaint->id} satisfaction ".($satisfaction === null ? 'cleared' : 'recorded').'.',
                entityType: FacilityComplaint::class,
                entityId: $complaint->id,
                metadata: ['before' => $before, 'after' => $satisfaction?->value, 'status_unchanged' => true],
            ));

            return $complaint->load('clientCompany', 'events.admin', 'reporterEmployee');
        });
    }
}
