<?php

namespace App\Http\Resources\Api\V1\Admin\Marketing;

use App\Enums\Marketing\MarketingRecordType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified shape for both XARUN and PROJECT records — the shared parent
 * fields plus exactly one of `xarun`/`project` populated based on `type`.
 * The create/edit forms stay separate per the SRS; this is only the
 * read/detail representation, matching the shared-parent DB design.
 */
class MarketingRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'record_number' => $this->record_number,
            'type' => $this->type->value,
            'assigned_team_id' => $this->assigned_team_id,
            'assigned_team_name' => $this->whenLoaded('assignedTeam', fn () => $this->assignedTeam?->name),
            'assigned_admin_id' => $this->assigned_admin_id,
            'assigned_admin_name' => $this->whenLoaded('assignedAdmin', fn () => $this->assignedAdmin?->full_name),
            'status' => $this->status->value,
            'description' => $this->description,
            'feedback' => $this->feedback,
            'brought_by_admin_id' => $this->brought_by_admin_id,
            'brought_by_admin_name' => $this->whenLoaded('broughtByAdmin', fn () => $this->broughtByAdmin?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
            'xarun' => $this->type === MarketingRecordType::Xarun
                ? $this->whenLoaded('xarunDetail', fn () => $this->xarunDetail ? [
                    'facility_name' => $this->xarunDetail->facility_name,
                    'manager_name' => $this->xarunDetail->manager_name,
                    'manager_title' => $this->xarunDetail->manager_title,
                    'phone' => $this->xarunDetail->phone,
                    'location' => $this->xarunDetail->location,
                    'needs' => $this->xarunDetail->needs,
                ] : null)
                : null,
            'project' => $this->type === MarketingRecordType::Project
                ? $this->whenLoaded('projectDetail', fn () => $this->projectDetail ? [
                    'responsible_party_type' => $this->projectDetail->responsible_party_type->value,
                    'company_name' => $this->projectDetail->company_name,
                    'responsible_person_name' => $this->projectDetail->responsible_person_name,
                    'phone' => $this->projectDetail->phone,
                    'location' => $this->projectDetail->location,
                    'project_type' => $this->projectDetail->project_type,
                    'project_size' => $this->projectDetail->project_size,
                    'construction_completion_date' => $this->projectDetail->construction_completion_date?->toDateString(),
                    'fayadhowr_work_date' => $this->projectDetail->fayadhowr_work_date?->toDateString(),
                ] : null)
                : null,
            'follow_ups' => FollowUpResource::collection($this->whenLoaded('followUps')),
            'quotations' => MarketingQuotationResource::collection($this->whenLoaded('quotations')),
        ];
    }
}
