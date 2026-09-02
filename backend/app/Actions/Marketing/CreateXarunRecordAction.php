<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Enums\Marketing\MarketingRecordStatus;
use App\Enums\Marketing\MarketingRecordType;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingRecord;
use App\Models\XarunDetail;
use App\Support\Marketing\MarketingRecordCodeGenerator;
use Illuminate\Support\Facades\DB;

class CreateXarunRecordAction
{
    public function __construct(private MarketingRecordCodeGenerator $codeGenerator) {}

    public function handle(array $data, Admin $actor): MarketingRecord
    {
        return DB::transaction(function () use ($data, $actor) {
            $record = MarketingRecord::query()->create([
                'record_number' => $this->codeGenerator->next(),
                'type' => MarketingRecordType::Xarun,
                'status' => MarketingRecordStatus::Pending,
                'assigned_team_id' => $data['assigned_team_id'] ?? null,
                'assigned_admin_id' => $data['assigned_admin_id'] ?? null,
                'description' => $data['description'] ?? null,
                'brought_by_admin_id' => $data['brought_by_admin_id'] ?? $actor->id,
                'created_by' => $actor->id,
            ]);

            XarunDetail::query()->create([
                'marketing_record_id' => $record->id,
                'facility_name' => $data['facility_name'],
                'manager_name' => $data['manager_name'] ?? null,
                'manager_title' => $data['manager_title'] ?? null,
                'phone' => $data['phone'] ?? null,
                'location' => $data['location'] ?? null,
                'needs' => $data['needs'] ?? null,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "XARUN '{$data['facility_name']}' registered ({$record->record_number}).",
                entityType: MarketingRecord::class,
                entityId: $record->id,
            ));

            return $record->load(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'xarunDetail']);
        });
    }
}
