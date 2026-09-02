<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\MarketingRecordStatus;
use App\Enums\Marketing\MarketingRecordType;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingRecord;
use App\Models\ProjectDetail;
use App\Support\Marketing\MarketingRecordCodeGenerator;
use Illuminate\Support\Facades\DB;

class CreateProjectRecordAction
{
    public function __construct(private MarketingRecordCodeGenerator $codeGenerator) {}

    public function handle(array $data, Admin $actor): MarketingRecord
    {
        return DB::transaction(function () use ($data, $actor) {
            $record = MarketingRecord::query()->create([
                'record_number' => $this->codeGenerator->next(),
                'type' => MarketingRecordType::Project,
                'status' => MarketingRecordStatus::Pending,
                'assigned_team_id' => $data['assigned_team_id'] ?? null,
                'assigned_admin_id' => $data['assigned_admin_id'] ?? null,
                'description' => $data['description'] ?? null,
                'brought_by_admin_id' => $data['brought_by_admin_id'] ?? $actor->id,
                'created_by' => $actor->id,
            ]);

            ProjectDetail::query()->create([
                'marketing_record_id' => $record->id,
                'responsible_party_type' => $data['responsible_party_type'],
                'company_name' => $data['company_name'] ?? null,
                'responsible_person_name' => $data['responsible_person_name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'location' => $data['location'] ?? null,
                'project_type' => $data['project_type'] ?? null,
                'project_size' => $data['project_size'] ?? null,
                'construction_completion_date' => $data['construction_completion_date'] ?? null,
                'fayadhowr_work_date' => $data['fayadhowr_work_date'] ?? null,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "PROJECT registered ({$record->record_number}).",
                entityType: MarketingRecord::class,
                entityId: $record->id,
            ));

            return $record->load(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'projectDetail']);
        });
    }
}
