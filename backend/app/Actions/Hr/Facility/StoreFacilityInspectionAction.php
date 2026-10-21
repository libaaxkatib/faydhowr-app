<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Enums\FacilityComplaintStatus;
use App\Enums\FacilitySatisfaction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\FacilityInspection;
use App\Support\Hr\Facility\FacilityInspectionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Records one inspection visit with its twelve checked items and any uniform issues, all or nothing. */
class StoreFacilityInspectionAction
{
    public function __construct(
        private readonly FacilityInspectionWriter $writer,
        private readonly StoreFacilityComplaintAction $complaints,
        private readonly TransitionFacilityComplaintAction $transition,
        private readonly RecordFacilityComplaintSatisfactionAction $satisfaction,
    ) {}

    /** @param  array<string, mixed>  $data  validated by StoreFacilityInspectionRequest */
    public function handle(array $data, Admin $actor): FacilityInspection
    {
        $companyId = (int) $data['client_company_id'];
        $date = CarbonImmutable::parse($data['inspected_on']);
        $uniformIssues = $data['uniform_issues'] ?? [];

        $this->writer->assertWorkplaceBelongsToCompany($data['work_location_id'] ?? null, $companyId);
        $this->writer->assertEmployeesWorkForCompany($uniformIssues, $companyId, $date);

        return DB::transaction(function () use ($data, $actor, $companyId, $uniformIssues): FacilityInspection {
            $inspection = FacilityInspection::query()->create([
                'client_company_id' => $companyId,
                'work_location_id' => $data['work_location_id'] ?? null,
                'inspector_name' => trim((string) $data['inspector_name']),
                'inspected_on' => $data['inspected_on'],
                'general_notes' => $data['general_notes'] ?? null,
                'next_follow_up_on' => $data['next_follow_up_on'] ?? null,
                'staff_complaints_reported' => (bool) $data['staff_complaints_reported'],
                'customer_complaints_reported' => (bool) $data['customer_complaints_reported'],
                'recorded_by' => $actor->id,
            ]);
            $this->writer->write($inspection, $data['items'], $uniformIssues);

            // Each complaint goes through the same actions as the Complaints screen, so the rules (a response before "answered", a note to
            // resolve, evidence for satisfaction), the history and the audit trail are identical. Any refusal rolls back the whole inspection.
            foreach ($data['complaints'] ?? [] as $entry) {
                $this->recordComplaint($inspection, $entry, $actor);
            }

            $company = ClientCompany::query()->find($companyId);
            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Facility inspection recorded for {$company?->name} on {$inspection->inspected_on->toDateString()}.",
                entityType: FacilityInspection::class,
                entityId: $inspection->id,
                metadata: $this->writer->summary($inspection),
            ));

            return $inspection->load('items', 'uniformIssues.employee', 'clientCompany', 'workLocation');
        });
    }

    /** @param  array<string, mixed>  $entry */
    private function recordComplaint(FacilityInspection $inspection, array $entry, Admin $actor): void
    {
        $complaint = $this->complaints->handle([
            ...$entry,
            'client_company_id' => $inspection->client_company_id,
            'facility_inspection_id' => $inspection->id,
            'reported_on' => $entry['reported_on'] ?? $inspection->inspected_on->toDateString(),
        ], $actor);

        $status = FacilityComplaintStatus::tryFrom((string) ($entry['status'] ?? 'open')) ?? FacilityComplaintStatus::Open;

        if ($status !== FacilityComplaintStatus::Open) {
            $complaint = $this->transition->handle($complaint, $status, $entry['status_note'] ?? null, null, $actor);
        }

        if (! empty($entry['satisfaction'])) {
            $this->satisfaction->handle($complaint, FacilitySatisfaction::from($entry['satisfaction']), $entry['satisfaction_evidence'] ?? null, null, $actor);
        }
    }
}
