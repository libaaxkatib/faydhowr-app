<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;

/**
 * Edits a company. The record keeps its id, so every assignment, payroll line, history entry and audit record follows it. When the NAME
 * changes (for example "5GHOTEL" -> "5G HOTEL"), the old spelling is kept as an alias of the same company, so a later import or search that
 * still uses the old spelling finds this company instead of creating a duplicate. Spaces inside the name are tidied (never removed).
 */
class UpdateClientCompanyAction
{
    public function handle(ClientCompany $clientCompany, array $data, Admin $actor): ClientCompany
    {
        $oldName = $clientCompany->name;

        if (isset($data['name']) && is_string($data['name'])) {
            $data['name'] = trim(preg_replace('/\s+/u', ' ', $data['name']) ?? $data['name']);
        }

        $clientCompany->update($data);
        $renamed = $clientCompany->wasChanged('name');

        if ($renamed) {
            $already = ClientCompanyAlias::query()->where('client_company_id', $clientCompany->id)->where('alias', $oldName)->exists();

            if (! $already) {
                ClientCompanyAlias::query()->create([
                    'client_company_id' => $clientCompany->id,
                    'alias' => $oldName,
                    'source' => 'rename',
                    'source_reference' => 'Previous name before it was changed to "'.$clientCompany->name.'"',
                    'created_by' => $actor->id,
                ]);
            }
        }

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: $renamed ? "Client company '{$oldName}' renamed to '{$clientCompany->name}'." : "Client company '{$clientCompany->name}' updated.",
            entityType: ClientCompany::class,
            entityId: $clientCompany->id,
            metadata: $renamed ? ['renamed_from' => $oldName, 'renamed_to' => $clientCompany->name, 'old_name_kept_as_alias' => true] : null,
        ));

        return $clientCompany;
    }
}
