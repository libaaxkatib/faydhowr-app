<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Position;

class CreatePositionAction
{
    public function handle(array $data, Admin $actor): Position
    {
        $position = Position::query()->create($data);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Position '{$position->name}' created.",
            entityType: Position::class,
            entityId: $position->id,
        ));

        return $position->load('department');
    }
}
