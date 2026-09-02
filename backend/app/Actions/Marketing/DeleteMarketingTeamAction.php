<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingTeam;

class DeleteMarketingTeamAction
{
    public function handle(MarketingTeam $team, Admin $actor): void
    {
        $name = $team->name;
        $id = $team->id;

        $team->delete();

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Marketing team '{$name}' deleted.",
            entityType: MarketingTeam::class,
            entityId: $id,
        ));
    }
}
