<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkLocation;

class UpdateWorkLocationAction
{
    public function handle(WorkLocation $workLocation, array $data, Admin $actor): WorkLocation
    {
        $workLocation->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Work location '{$workLocation->name}' updated.",
            entityType: WorkLocation::class,
            entityId: $workLocation->id,
        ));

        return $workLocation->load('clientCompany');
    }
}
