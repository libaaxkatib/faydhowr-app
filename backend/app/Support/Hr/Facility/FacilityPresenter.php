<?php

namespace App\Support\Hr\Facility;

use App\Enums\FacilityItemKey;
use App\Models\FacilityComplaint;
use App\Models\FacilityDeepCleaning;
use App\Models\FacilityInspection;

/** Response shapes of the Facility Inspections API (kept in one place so list and detail agree). */
final class FacilityPresenter
{
    /** @return array<string, mixed> */
    public static function inspection(FacilityInspection $i, bool $detail = false): array
    {
        $i->loadMissing('clientCompany:id,name,category', 'workLocation:id,name', 'recordedBy:id,full_name');
        $items = $i->relationLoaded('items') ? $i->items : $i->items()->get();
        $issues = $i->relationLoaded('uniformIssues') ? $i->uniformIssues : $i->uniformIssues()->with('employee:id,employee_number,full_name')->get();
        $unsatisfactory = $items->filter(fn ($item): bool => $item->result?->value === 'unsatisfactory');

        $row = [
            'id' => $i->id,
            'client_company_id' => $i->client_company_id,
            'company' => $i->clientCompany?->name,
            'work_location_id' => $i->work_location_id,
            'workplace' => $i->workLocation?->name,
            'inspector_name' => $i->inspector_name,
            'inspected_on' => $i->inspected_on?->toDateString(),
            'next_follow_up_on' => $i->next_follow_up_on?->toDateString(),
            'general_notes' => $i->general_notes,
            // The inspector's own answer: true = a complaint of that kind was recorded, false = "no complaint", null = not recorded (older inspections).
            'staff_complaints_reported' => $i->staff_complaints_reported,
            'customer_complaints_reported' => $i->customer_complaints_reported,
            'staff_complaint_count' => (int) ($i->staff_complaints_count ?? 0),
            'customer_complaint_count' => (int) ($i->customer_complaints_count ?? 0),
            'recorded_by' => $i->recordedBy?->full_name,
            'unsatisfactory_count' => $unsatisfactory->count(),
            'uniform_issue_count' => $issues->count(),
            'voided' => $i->voided_at !== null,
            'voided_at' => $i->voided_at?->toIso8601String(),
            'void_reason' => $i->void_reason,
            'created_at' => $i->created_at?->toIso8601String(),
        ];

        // The Deep Cleaning this inspection recorded (null in the list unless one is counted). A routine inspection records none.
        $i->loadMissing('deepCleaning');
        $row['deep_cleaning_completed_on'] = $i->deepCleaning !== null && $i->deepCleaning->voided_at === null ? $i->deepCleaning->completed_on?->toDateString() : null;

        if (! $detail) {
            return $row;
        }

        $i->loadMissing('deepCleaning.recordedBy:id,full_name', 'deepCleaning.changedBy:id,full_name');
        $row['deep_cleaning'] = self::deepCleaning($i->deepCleaning);

        $row['items'] = collect(FacilityItemKey::cases())->map(function (FacilityItemKey $key) use ($items): array {
            $item = $items->first(fn ($x): bool => $x->item_key === $key);

            return [
                'item_key' => $key->value,
                'label' => $key->label(),
                'applicable' => $item?->applicable ?? false,
                'result' => $item?->result?->value,
                'issue_description' => $item?->issue_description,
                'corrective_action' => $item?->corrective_action,
            ];
        })->all();
        $row['uniform_issues'] = $issues->map(fn ($u): array => [
            'id' => $u->id,
            'employee_id' => $u->employee_id,
            'employee_number' => $u->employee?->employee_number,
            'employee_name' => $u->employee?->full_name,
            'issue' => $u->issue,
            'warning_given' => (bool) $u->warning_given,
            'reason_not_worn' => $u->reason_not_worn,
            'corrective_action' => $u->corrective_action,
            'follow_up_on' => $u->follow_up_on?->toDateString(),
        ])->values()->all();
        $row['complaints'] = $i->complaints()->counted()->with('clientCompany:id,name,category', 'reporterEmployee:id,employee_number,full_name')->orderBy('id')->get()
            ->map(fn (FacilityComplaint $c): array => self::complaint($c))->all();
        $row['staff_complaint_count'] = collect($row['complaints'])->where('type', 'staff')->count();
        $row['customer_complaint_count'] = collect($row['complaints'])->where('type', 'customer')->count();

        return $row;
    }

    /** @return array<string, mixed>|null */
    public static function deepCleaning(?FacilityDeepCleaning $d): ?array
    {
        if ($d === null) {
            return null;
        }

        return [
            'id' => $d->id,
            'completed_on' => $d->completed_on?->toDateString(),
            'notes' => $d->notes,
            'recorded_by' => $d->recordedBy?->full_name,
            'recorded_at' => $d->created_at?->toIso8601String(),
            'changed_by' => $d->changedBy?->full_name,
            'change_reason' => $d->change_reason,
            'withdrawn' => $d->voided_at !== null,
            'withdrawn_at' => $d->voided_at?->toIso8601String(),
            'withdrawn_reason' => $d->void_reason,
        ];
    }

    /** @return array<string, mixed> */
    public static function complaint(FacilityComplaint $c, bool $detail = false): array
    {
        $c->loadMissing('clientCompany:id,name,category', 'reporterEmployee:id,employee_number,full_name');

        $row = [
            'id' => $c->id,
            'client_company_id' => $c->client_company_id,
            'company' => $c->clientCompany?->name,
            'facility_inspection_id' => $c->facility_inspection_id,
            'type' => $c->type->value,
            'type_label' => $c->type->label(),
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'details' => $c->details,
            'reporter_name' => $c->reporter_name,
            'reporter_type' => $c->reporter_type,
            'reporter_phone' => $c->reporter_phone,
            'reporter_employee_id' => $c->reporter_employee_id,
            'reporter_employee' => $c->reporterEmployee === null ? null : "{$c->reporterEmployee->full_name} ({$c->reporterEmployee->employee_number})",
            'responsible_person' => $c->responsible_person,
            'response' => $c->response,
            'corrective_action' => $c->corrective_action,
            'follow_up_on' => $c->follow_up_on?->toDateString(),
            'reported_on' => $c->reported_on?->toDateString(),
            'responded_on' => $c->responded_on?->toDateString(),
            'resolved_on' => $c->resolved_on?->toDateString(),
            // Explicit documented feedback only. null means "not recorded" - it is never read as satisfied or unsatisfied.
            'satisfaction' => $c->satisfaction?->value,
            'satisfaction_label' => $c->satisfaction?->label() ?? 'Not recorded',
            'satisfaction_evidence' => $c->satisfaction_evidence,
            'satisfaction_recorded_on' => $c->satisfaction_recorded_on?->toDateString(),
            'voided' => $c->voided_at !== null,
            'void_reason' => $c->void_reason,
            'created_at' => $c->created_at?->toIso8601String(),
        ];

        if ($detail) {
            $row['events'] = $c->events()->with('admin:id,full_name')->get()->map(fn ($e): array => [
                'id' => $e->id,
                'event' => $e->event,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'note' => $e->note,
                'by' => $e->admin?->full_name,
                'at' => $e->created_at?->toIso8601String(),
            ])->all();
        }

        return $row;
    }
}
