<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

class DeleteWorkLocationAction
{
    public function handle(WorkLocation $workLocation, Admin $actor): void
    {
        if ($workLocation->workAssignments()->exists()) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'This work location cannot be deleted because it has employee work-assignment history.',
                    'WORK_LOCATION_HAS_ASSIGNMENTS',
                    422,
                ),
            );
        }

        $name = $workLocation->name;
        $id = $workLocation->id;

        $workLocation->delete();

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Work location '{$name}' deleted.",
            entityType: WorkLocation::class,
            entityId: $id,
        ));
    }
}
