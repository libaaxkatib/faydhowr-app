<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Enums\WorkforceRequestStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\WorkforceRequest;
use App\Models\WorkforceRequestMatch;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * "HR confirmation" (docs/HRM_MARKETING_SRS.md HR Phase 2). Per the confirmed
 * Phase 2 scope, this only records the match - it never changes
 * employee.status/pipeline_stage/waiting_since and never creates an
 * EmployeeWorkAssignment. The employee stays Waiting; moving them to Active
 * and creating the real placement is Phase 3 ("Workforce Operations")
 * business, done separately via the existing Advance/Assign Work Location flow.
 */
class ConfirmWorkforceRequestMatchAction
{
    public function handle(WorkforceRequest $request, Employee $employee, ?string $notes, Admin $actor): WorkforceRequest
    {
        if ($employee->status !== EmployeeStatus::Waiting) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' is not currently in the Waiting queue.",
                    'EMPLOYEE_NOT_WAITING',
                    422,
                ),
            );
        }

        if (in_array($request->status, [WorkforceRequestStatus::Fulfilled, WorkforceRequestStatus::Cancelled], true)) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Workforce request #{$request->id} is {$request->status->value} and can no longer accept new matches.",
                    'WORKFORCE_REQUEST_NOT_OPEN',
                    422,
                ),
            );
        }

        if ($request->matches()->where('employee_id', $employee->id)->exists()) {
            throw new HttpResponseException(
                ApiResponse::error(
                    "Employee '{$employee->full_name}' is already matched to workforce request #{$request->id}.",
                    'WORKFORCE_REQUEST_ALREADY_MATCHED',
                    422,
                ),
            );
        }

        return DB::transaction(function () use ($request, $employee, $notes, $actor) {
            WorkforceRequestMatch::query()->create([
                'workforce_request_id' => $request->id,
                'employee_id' => $employee->id,
                'confirmed_by' => $actor->id,
                'notes' => $notes,
            ]);

            $matchedCount = $request->matches()->count();
            $request->update([
                'status' => $matchedCount >= $request->quantity_needed
                    ? WorkforceRequestStatus::Fulfilled
                    : WorkforceRequestStatus::PartiallyFilled,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$employee->full_name}' matched to workforce request #{$request->id}.",
                entityType: WorkforceRequest::class,
                entityId: $request->id,
                metadata: ['employee_id' => $employee->id],
            ));

            return $request->load('workLocation.clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee');
        });
    }
}
