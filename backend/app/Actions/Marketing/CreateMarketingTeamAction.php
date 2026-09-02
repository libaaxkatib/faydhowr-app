<?php

namespace App\Actions\Marketing;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\MarketingTeam;

class CreateMarketingTeamAction
{
    public function handle(array $data, Admin $actor): MarketingTeam
    {
        $team = MarketingTeam::query()->create($data);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Marketing team '{$team->name}' created.",
            entityType: MarketingTeam::class,
            entityId: $team->id,
        ));

        return $team->load('members');
    }
}
