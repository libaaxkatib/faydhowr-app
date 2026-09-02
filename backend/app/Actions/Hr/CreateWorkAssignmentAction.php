<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the work location's approved capacity (e.g. the seeded Fayadhowr
 * Office row, capacity 15): a Super Admin may bypass it by explicitly
 * passing override_capacity=true; no other role can, even if it sends the
 * flag. Done here (not in the FormRequest) because it needs the acting
 * admin's role, which the request layer doesn't carry.
 */
class CreateWorkAssignmentAction
{
    public function handle(Employee $employee, array $data, Admin $actor): EmployeeWorkAssignment
    {
        $workLocation = WorkLocation::query()->findOrFail($data['work_location_id']);

        if ($workLocation->capacity !== null) {
            $activeCount = $workLocation->workAssignments()->where('status', WorkAssignmentStatus::Active)->count();
            $overriding = $actor->role === AdminRole::SuperAdmin && ($data['override_capacity'] ?? false) === true;

            if ($activeCount >= $workLocation->capacity && ! $overriding) {
                throw new HttpResponseException(
                    ApiResponse::error(
                        "This work location has reached its approved capacity ({$workLocation->capacity}).",
                        'WORK_LOCATION_CAPACITY_EXCEEDED',
                        422,
                    ),
                );
            }
        }

        return DB::transaction(function () use ($employee, $data, $actor) {
            $assignment = EmployeeWorkAssignment::query()->create([
                'employee_id' => $employee->id,
                'work_location_id' => $data['work_location_id'],
                'position_id' => $data['position_id'] ?? null,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'salary_amount' => $data['salary_amount'],
                'salary_currency' => $data['salary_currency'],
                'salary_frequency' => $data['salary_frequency'],
                'status' => WorkAssignmentStatus::Active,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Work assignment created for employee '{$employee->full_name}' ({$employee->employee_number}).",
                entityType: EmployeeWorkAssignment::class,
                entityId: $assignment->id,
            ));

            return $assignment->load(['workLocation.clientCompany', 'position']);
        });
    }
}
