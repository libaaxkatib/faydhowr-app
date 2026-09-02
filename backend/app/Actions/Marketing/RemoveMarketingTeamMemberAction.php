<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingTeam;

class RemoveMarketingTeamMemberAction
{
    public function handle(MarketingTeam $team, int $adminId, Admin $actor): MarketingTeam
    {
        $team->members()->detach([$adminId]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Admin #{$adminId} removed from marketing team '{$team->name}'.",
            entityType: MarketingTeam::class,
            entityId: $team->id,
            metadata: ['admin_id' => $adminId],
        ));

        return $team->load('members');
    }
}
