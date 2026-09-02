<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Position;

class UpdatePositionAction
{
    public function handle(Position $position, array $data, Admin $actor): Position
    {
        $position->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Position '{$position->name}' updated.",
            entityType: Position::class,
            entityId: $position->id,
        ));

        return $position->load('department');
    }
}
