<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FacilityComplaint;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityInspectionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Edits a complaint's details, people and response. It NEVER changes the status: recording a response is not the same as answering or
 * resolving the complaint, and the status only moves through TransitionFacilityComplaintAction (which writes the history).
 */
class UpdateFacilityComplaintAction
{
    public function __construct(private readonly FacilityInspectionWriter $writer) {}

    /** @param  array<string, mixed>  $data  validated by UpdateFacilityComplaintRequest */
    public function handle(FacilityComplaint $complaint, array $data, Admin $actor): FacilityComplaint
    {
        if ($complaint->voided_at !== null) {
            throw new HttpResponseException(ApiResponse::error('A voided complaint cannot be edited.', 'FACILITY_COMPLAINT_VOIDED', 422));
        }

        if (! empty($data['reporter_employee_id'])) {
            $this->writer->assertEmployeesWorkForCompany([['employee_id' => $data['reporter_employee_id']]], $complaint->client_company_id, CarbonImmutable::parse($data['reported_on'] ?? $complaint->reported_on));
        }

        $fields = array_intersect_key($data, array_flip(['details', 'reporter_name', 'reporter_type', 'reporter_phone', 'reporter_employee_id', 'responsible_person', 'response', 'corrective_action', 'follow_up_on', 'reported_on', 'responded_on']));

        if (array_key_exists('response', $fields)) {
            $fields['response'] = trim((string) $fields['response']) === '' ? null : trim((string) $fields['response']);
            $fields['responded_on'] = $fields['response'] === null ? null : ($fields['responded_on'] ?? $complaint->responded_on?->toDateString() ?? now()->toDateString());
        }

        return DB::transaction(function () use ($complaint, $fields, $actor): FacilityComplaint {
            $responseBefore = $complaint->response;
            $complaint->update($fields);

            if ($complaint->wasChanged()) {
                $responseChanged = $responseBefore !== $complaint->response;
                $complaint->events()->create([
                    'admin_id' => $actor->id,
                    'event' => $responseChanged ? 'response_recorded' : 'updated',
                    'note' => $responseChanged ? 'The response was entered or changed; the status was not changed.' : null,
                ]);
                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Complaint #{$complaint->id} updated.",
                    entityType: FacilityComplaint::class,
                    entityId: $complaint->id,
                    metadata: ['fields' => array_keys($complaint->getChanges()), 'status_unchanged' => true],
                ));
            }

            return $complaint->load('clientCompany', 'events.admin', 'reporterEmployee');
        });
    }
}
