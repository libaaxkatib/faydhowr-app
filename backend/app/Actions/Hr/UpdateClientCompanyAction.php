<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;

class UpdateClientCompanyAction
{
    public function handle(ClientCompany $clientCompany, array $data, Admin $actor): ClientCompany
    {
        $clientCompany->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Client company '{$clientCompany->name}' updated.",
            entityType: ClientCompany::class,
            entityId: $clientCompany->id,
        ));

        return $clientCompany;
    }
}
