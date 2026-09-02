<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\ClientStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkLocation;

class CreateWorkLocationAction
{
    public function handle(array $data, Admin $actor): WorkLocation
    {
        $workLocation = WorkLocation::query()->create([
            ...$data,
            'status' => $data['status'] ?? ClientStatus::Active,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Work location '{$workLocation->name}' created.",
            entityType: WorkLocation::class,
            entityId: $workLocation->id,
        ));

        return $workLocation->load('clientCompany');
    }
}
