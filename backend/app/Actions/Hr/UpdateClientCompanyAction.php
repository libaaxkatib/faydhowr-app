<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\ClientStatus;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use App\Models\CompanyClosure;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\CompanyEmployees\CompanyAssignmentGuard;
use App\Support\Hr\Payroll\OpenPayrollNames;

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

        // Closing and reopening a company is its own recorded action (it ends / restores the assignments): the status field cannot be used around it.
        if (isset($data['status']) && $data['status'] !== $clientCompany->status->value) {
            $closed = CompanyClosure::query()->where('client_company_id', $clientCompany->id)->where('status', CompanyClosure::STATUS_CLOSED)->exists();

            if ($data['status'] === ClientStatus::Active->value && $closed) {
                CompanyAssignmentGuard::fail("{$clientCompany->name} was closed. Use Reopen company to open it again; that also decides which assignments come back.", 'COMPANY_CLOSED_USE_REOPEN');
            }

            if ($data['status'] === ClientStatus::Inactive->value && EmployeeWorkAssignment::query()->where('client_company_id', $clientCompany->id)->where('status', WorkAssignmentStatus::Active)->exists()) {
                CompanyAssignmentGuard::fail("{$clientCompany->name} still has active assignments. Use Close company: it ends them on the closing day so they stop being paid.", 'COMPANY_HAS_ACTIVE_ASSIGNMENTS_USE_CLOSE');
            }
        }

        $clientCompany->update($data);
        $renamed = $clientCompany->wasChanged('name');

        if ($renamed) {
            OpenPayrollNames::company($clientCompany);
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
