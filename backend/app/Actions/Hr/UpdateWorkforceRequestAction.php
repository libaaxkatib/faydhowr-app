<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\WorkforceRequest;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use App\Support\Hr\CompanyLocationResolver;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Edits criteria fields only - status is never hand-edited here, it's derived
 * from confirmed matches (ConfirmWorkforceRequestMatchAction) and cancellation
 * (CancelWorkforceRequestAction), so it can never drift out of sync.
 *
 * The one extra thing it can do is COMPLETE a missing work location later (the
 * location is optional); an existing location is never swapped.
 */
class UpdateWorkforceRequestAction
{
    public function handle(WorkforceRequest $request, array $data, Admin $actor): WorkforceRequest
    {
        if (! empty($data['work_location_id'])) {
            $this->completeLocation($request, (int) $data['work_location_id']);
        }

        unset($data['work_location_id']);
        $request->update($data);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Workforce request #{$request->id} updated.",
            entityType: WorkforceRequest::class,
            entityId: $request->id,
        ));

        return $request->load('workLocation.clientCompany', 'clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee');
    }

    private function completeLocation(WorkforceRequest $request, int $locationId): void
    {
        if ($request->work_location_id !== null) {
            if ((int) $request->work_location_id === $locationId) {
                return;
            }

            throw new HttpResponseException(ApiResponse::error('This request already has a work location.', 'REQUEST_LOCATION_ALREADY_SET', 422));
        }

        CompanyLocationResolver::assertCompletes(WorkLocation::query()->findOrFail($locationId), $request->client_company_id);
        $request->update(['work_location_id' => $locationId]);
    }
}
