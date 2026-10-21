<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkLocation;
use App\Support\Hr\Payroll\OpenPayrollNames;

class UpdateWorkLocationAction
{
    public function handle(WorkLocation $workLocation, array $data, Admin $actor): WorkLocation
    {
        $oldName = $workLocation->name;
        $workLocation->update($data);
        $renamed = $workLocation->wasChanged('name');

        if ($renamed) {
            OpenPayrollNames::workplace($workLocation);
        }

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: $renamed ? "Work location '{$oldName}' renamed to '{$workLocation->name}'." : "Work location '{$workLocation->name}' updated.",
            entityType: WorkLocation::class,
            entityId: $workLocation->id,
            metadata: $renamed ? ['renamed_from' => $oldName, 'renamed_to' => $workLocation->name] : null,
        ));

        return $workLocation->load('clientCompany');
    }
}
