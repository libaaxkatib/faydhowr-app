<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FacilityInspection;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityInspectionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Corrects an inspection. The company never changes (that would move history from one company to another); a voided inspection cannot be
 * edited. The audit entry records what the inspection looked like before and after.
 */
class UpdateFacilityInspectionAction
{
    public function __construct(private readonly FacilityInspectionWriter $writer) {}

    /** @param  array<string, mixed>  $data  validated by UpdateFacilityInspectionRequest */
    public function handle(FacilityInspection $inspection, array $data, Admin $actor): FacilityInspection
    {
        if ($inspection->voided_at !== null) {
            throw new HttpResponseException(ApiResponse::error('A voided inspection cannot be edited.', 'FACILITY_INSPECTION_VOIDED', 422));
        }

        $date = CarbonImmutable::parse($data['inspected_on'] ?? $inspection->inspected_on);
        $uniformIssues = $data['uniform_issues'] ?? null;

        $this->writer->assertWorkplaceBelongsToCompany(array_key_exists('work_location_id', $data) ? $data['work_location_id'] : $inspection->work_location_id, $inspection->client_company_id);
        $this->writer->assertEmployeesWorkForCompany($uniformIssues ?? $inspection->uniformIssues()->get()->map(fn ($u) => ['employee_id' => $u->employee_id])->all(), $inspection->client_company_id, $date);

        return DB::transaction(function () use ($inspection, $data, $actor, $uniformIssues): FacilityInspection {
            $before = $this->writer->summary($inspection);

            $inspection->update(array_intersect_key($data, array_flip(['work_location_id', 'inspector_name', 'inspected_on', 'general_notes', 'next_follow_up_on', 'staff_complaints_reported', 'customer_complaints_reported'])));

            if (isset($data['items']) || $uniformIssues !== null) {
                $this->writer->write(
                    $inspection,
                    $data['items'] ?? $inspection->items()->get()->map(fn ($i) => [
                        'item_key' => $i->item_key->value, 'applicable' => $i->applicable, 'result' => $i->result?->value,
                        'issue_description' => $i->issue_description, 'corrective_action' => $i->corrective_action,
                    ])->all(),
                    $uniformIssues ?? $inspection->uniformIssues()->get()->map(fn ($u) => [
                        'employee_id' => $u->employee_id, 'issue' => $u->issue, 'warning_given' => $u->warning_given, 'reason_not_worn' => $u->reason_not_worn,
                        'corrective_action' => $u->corrective_action, 'follow_up_on' => $u->follow_up_on?->toDateString(),
                    ])->all(),
                );
            }

            $inspection->refresh();
            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Facility inspection #{$inspection->id} corrected.",
                entityType: FacilityInspection::class,
                entityId: $inspection->id,
                metadata: ['before' => $before, 'after' => $this->writer->summary($inspection)],
            ));

            return $inspection->load('items', 'uniformIssues.employee', 'clientCompany', 'workLocation');
        });
    }
}
