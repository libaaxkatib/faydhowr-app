<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\FacilityInspectionSchedule;

/** Sets how often one company/workplace is to be inspected. Changing it never rewrites past inspections; the change is audited with the old value. */
class SetFacilityInspectionScheduleAction
{
    /** @param  array{frequency_days: int, due_soon_days?: ?int, notes?: ?string}  $data */
    public function handle(ClientCompany $company, array $data, Admin $actor): FacilityInspectionSchedule
    {
        $existing = FacilityInspectionSchedule::query()->where('client_company_id', $company->id)->first();
        $before = $existing === null ? null : ['frequency_days' => $existing->frequency_days, 'due_soon_days' => $existing->due_soon_days];

        $schedule = FacilityInspectionSchedule::query()->updateOrCreate(
            ['client_company_id' => $company->id],
            [
                'frequency_days' => (int) $data['frequency_days'],
                'due_soon_days' => (int) ($data['due_soon_days'] ?? $existing?->due_soon_days ?? 7),
                'notes' => $data['notes'] ?? $existing?->notes,
                'set_by' => $actor->id,
            ],
        );

        event(AuditEvent::record(
            action: $existing === null ? AuditAction::Create : AuditAction::Update,
            admin: $actor,
            description: "Inspection frequency for {$company->name} set to every {$schedule->frequency_days} day(s).",
            entityType: FacilityInspectionSchedule::class,
            entityId: $schedule->id,
            metadata: ['before' => $before, 'after' => ['frequency_days' => $schedule->frequency_days, 'due_soon_days' => $schedule->due_soon_days]],
        ));

        return $schedule;
    }
}
