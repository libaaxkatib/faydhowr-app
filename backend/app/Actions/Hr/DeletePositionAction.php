<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Position;

class DeletePositionAction
{
    public function handle(Position $position, Admin $actor): void
    {
        $name = $position->name;
        $id = $position->id;

        $position->delete();

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Position '{$name}' deleted.",
            entityType: Position::class,
            entityId: $id,
        ));
    }
}
