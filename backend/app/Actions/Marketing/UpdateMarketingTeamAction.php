<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingTeam;

class UpdateMarketingTeamAction
{
    public function handle(MarketingTeam $team, array $data, Admin $actor): MarketingTeam
    {
        $team->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Marketing team '{$team->name}' updated.",
            entityType: MarketingTeam::class,
            entityId: $team->id,
        ));

        return $team->load('members');
    }
}
