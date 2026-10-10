<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\WorkforceRequestStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkforceRequest;
use App\Models\WorkLocation;
use App\Support\Hr\CompanyLocationResolver;

/**
 * A company can ask for staff before the exact workplace is known: the company
 * is stored on the request and the work location is optional (approved
 * decision, same rule as work assignments).
 */
class CreateWorkforceRequestAction
{
    public function handle(array $data, Admin $actor): WorkforceRequest
    {
        $location = ! empty($data['work_location_id']) ? WorkLocation::query()->findOrFail($data['work_location_id']) : null;
        $companyId = CompanyLocationResolver::resolve($location, $data['client_company_id'] ?? null);

        $request = WorkforceRequest::query()->create([
            ...$data,
            'work_location_id' => $location?->id,
            'client_company_id' => $companyId,
            'status' => WorkforceRequestStatus::Open,
            'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: $location
                ? "Workforce request #{$request->id} created for work location #{$location->id}."
                : "Workforce request #{$request->id} created for client company #{$companyId} (no work location yet).",
            entityType: WorkforceRequest::class,
            entityId: $request->id,
        ));

        return $request->load('workLocation.clientCompany', 'clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee');
    }
}
