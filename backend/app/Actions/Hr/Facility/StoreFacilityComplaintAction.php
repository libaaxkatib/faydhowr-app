<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Enums\FacilityComplaintStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\FacilityComplaint;
use App\Models\FacilityInspection;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityInspectionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Registers a complaint. It always starts as "new / open": whatever a caller sends, the status is not taken from the request, and a response
 * given at registration does not make it answered or resolved (that is an explicit step, see TransitionFacilityComplaintAction).
 */
class StoreFacilityComplaintAction
{
    public function __construct(private readonly FacilityInspectionWriter $writer) {}

    /** @param  array<string, mixed>  $data  validated by StoreFacilityComplaintRequest */
    public function handle(array $data, Admin $actor): FacilityComplaint
    {
        $companyId = (int) $data['client_company_id'];
        $reportedOn = CarbonImmutable::parse($data['reported_on']);

        if (! empty($data['facility_inspection_id'])) {
            $sameCompany = FacilityInspection::query()->where('id', $data['facility_inspection_id'])->where('client_company_id', $companyId)->exists();

            if (! $sameCompany) {
                throw new HttpResponseException(ApiResponse::error('The linked inspection belongs to a different company.', 'INSPECTION_NOT_OF_COMPANY', 422));
            }
        }

        if (! empty($data['reporter_employee_id'])) {
            // A staff member who raises a complaint about a site must work for that company.
            $this->writer->assertEmployeesWorkForCompany([['employee_id' => $data['reporter_employee_id']]], $companyId, $reportedOn);
        }

        return DB::transaction(function () use ($data, $actor, $companyId): FacilityComplaint {
            $hasResponse = isset($data['response']) && trim((string) $data['response']) !== '';
            $complaint = FacilityComplaint::query()->create([
                'client_company_id' => $companyId,
                'facility_inspection_id' => $data['facility_inspection_id'] ?? null,
                'type' => $data['type'],
                'details' => trim((string) $data['details']),
                'reporter_name' => $data['reporter_name'] ?? null,
                'reporter_type' => $data['reporter_type'] ?? null,
                'reporter_phone' => $data['reporter_phone'] ?? null,
                'reporter_employee_id' => $data['reporter_employee_id'] ?? null,
                'responsible_person' => $data['responsible_person'] ?? null,
                'response' => $hasResponse ? trim((string) $data['response']) : null,
                'corrective_action' => isset($data['corrective_action']) && trim((string) $data['corrective_action']) !== '' ? trim((string) $data['corrective_action']) : null,
                'follow_up_on' => $data['follow_up_on'] ?? null,
                'reported_on' => $data['reported_on'],
                'responded_on' => $hasResponse ? ($data['responded_on'] ?? now()->toDateString()) : null,
                'status' => FacilityComplaintStatus::Open->value,
                'recorded_by' => $actor->id,
            ]);
            // A complaint linked to an inspection means that inspection did have a complaint of this kind.
            $linkedInspection = $complaint->facility_inspection_id === null ? null : FacilityInspection::query()->find($complaint->facility_inspection_id);
            $flag = $complaint->type->value.'_complaints_reported';
            if ($linkedInspection !== null && $linkedInspection->{$flag} !== true) {
                $linkedInspection->update([$flag => true]);
            }

            $complaint->events()->create(['admin_id' => $actor->id, 'event' => 'created', 'to_status' => FacilityComplaintStatus::Open->value, 'note' => $hasResponse ? 'A response was entered at registration; the complaint stays open until someone marks it answered.' : null]);

            $company = ClientCompany::query()->find($companyId);
            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: ucfirst($complaint->type->value)." complaint registered for {$company?->name}.",
                entityType: FacilityComplaint::class,
                entityId: $complaint->id,
                metadata: ['type' => $complaint->type->value, 'client_company_id' => $companyId],
            ));

            return $complaint->load('clientCompany', 'events.admin', 'reporterEmployee');
        });
    }
}
