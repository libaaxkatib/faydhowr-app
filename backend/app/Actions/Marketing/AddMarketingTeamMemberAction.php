<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingTeam;

class AddMarketingTeamMemberAction
{
    public function handle(MarketingTeam $team, int $adminId, Admin $actor): MarketingTeam
    {
        $team->members()->syncWithoutDetaching([$adminId]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Admin #{$adminId} added to marketing team '{$team->name}'.",
            entityType: MarketingTeam::class,
            entityId: $team->id,
            metadata: ['admin_id' => $adminId],
        ));

        return $team->load('members');
    }
}
