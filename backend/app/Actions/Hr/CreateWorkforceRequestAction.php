<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\WorkforceRequestStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkforceRequest;

class CreateWorkforceRequestAction
{
    public function handle(array $data, Admin $actor): WorkforceRequest
    {
        $request = WorkforceRequest::query()->create([
            ...$data,
            'status' => WorkforceRequestStatus::Open,
            'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Workforce request #{$request->id} created for work location #{$request->work_location_id}.",
            entityType: WorkforceRequest::class,
            entityId: $request->id,
        ));

        return $request->load('workLocation.clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee');
    }
}
