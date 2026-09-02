<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\ClientStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;

class CreateClientCompanyAction
{
    public function handle(array $data, Admin $actor): ClientCompany
    {
        $clientCompany = ClientCompany::query()->create([
            ...$data,
            'status' => $data['status'] ?? ClientStatus::Active,
            'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Client company '{$clientCompany->name}' created.",
            entityType: ClientCompany::class,
            entityId: $clientCompany->id,
        ));

        return $clientCompany;
    }
}
