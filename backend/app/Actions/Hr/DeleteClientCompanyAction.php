<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

class DeleteClientCompanyAction
{
    public function handle(ClientCompany $clientCompany, Admin $actor): void
    {
        if ($clientCompany->workLocations()->exists()) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'This client company cannot be deleted while it still has work locations.',
                    'CLIENT_COMPANY_HAS_WORK_LOCATIONS',
                    422,
                ),
            );
        }

        $name = $clientCompany->name;
        $id = $clientCompany->id;

        $clientCompany->delete();

        event(AuditEvent::record(
            action: AuditAction::Delete,
            admin: $actor,
            description: "Client company '{$name}' deleted.",
            entityType: ClientCompany::class,
            entityId: $id,
        ));
    }
}
