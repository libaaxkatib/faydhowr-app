<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentSalaryHistory;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use App\Support\Hr\CompanyLocationResolver;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Enforces the work location's approved capacity (e.g. the seeded Fayadhowr
 * Office row, capacity 15): a Super Admin may bypass it by explicitly
 * passing override_capacity=true; no other role can, even if it sends the
 * flag. Done here (not in the FormRequest) because it needs the acting
 * admin's role, which the request layer doesn't carry.
 *
 * A work location is OPTIONAL (approved decision): a client assignment is
 * recorded against its company (client_company_id) and the specific location
 * is filled in whenever known. No placeholder location is ever invented.
 *
 * Duplicate guard: an employee cannot hold two active assignments at the same
 * work location, nor two active assignments at the same company when either
 * one has no location (it would be ambiguous whether they are the same job).
 * Concurrent active assignments at DIFFERENT known locations remain valid by
 * design - see the employee_work_assignments migration's comment.
 */
class CreateWorkAssignmentAction
{
    public function handle(Employee $employee, array $data, Admin $actor): EmployeeWorkAssignment
    {
        $workLocation = ! empty($data['work_location_id'])
            ? WorkLocation::query()->findOrFail($data['work_location_id'])
            : null;

        $companyId = CompanyLocationResolver::resolve($workLocation, $data['client_company_id'] ?? null);

        $this->guardAgainstDuplicates($employee, $workLocation, $companyId);
        $this->guardCapacity($workLocation, $data, $actor);

        return DB::transaction(function () use ($employee, $data, $actor, $workLocation, $companyId) {
            $predecessor = $this->lockPredecessor($employee, $data, $companyId);

            $assignment = EmployeeWorkAssignment::query()->create([
                'employee_id' => $employee->id,
                'work_location_id' => $workLocation?->id,
                'client_company_id' => $companyId,
                'replaces_assignment_id' => $predecessor?->id,
                'position_id' => $data['position_id'] ?? null,
                'assignment_type' => $data['assignment_type'] ?? AssignmentType::Permanent->value,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'salary_amount' => $data['salary_amount'],
                'salary_currency' => $data['salary_currency'],
                'salary_frequency' => $data['salary_frequency'],
                'status' => WorkAssignmentStatus::Active,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            AssignmentSalaryHistory::query()->create([
                'work_assignment_id' => $assignment->id,
                'effective_from' => $data['start_date'],
                'amount' => $data['salary_amount'],
                'currency' => $data['salary_currency'],
                'frequency' => $data['salary_frequency'],
                'reason' => 'Initial salary',
                'changed_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Work assignment created for employee '{$employee->full_name}' ({$employee->employee_number}).".($predecessor ? " It takes over assignment #{$predecessor->id}." : ''),
                entityType: EmployeeWorkAssignment::class,
                entityId: $assignment->id,
                metadata: $predecessor === null ? [] : [
                    'replaces_assignment_id' => $predecessor->id,
                    'predecessor_last_paid_day' => $predecessor->end_date->toDateString(),
                    'uncovered_gap_days' => PositionSuccession::gapDays($predecessor->end_date, $assignment->start_date),
                ],
            ));

            return $assignment->load(['workLocation.clientCompany', 'clientCompany', 'position', 'replaces']);
        });
    }

    /**
     * Permanent succession (optional): locks the assignment being taken over
     * and refuses overlap (the same calendar day paid to two people). A gap is
     * allowed and reported. Not related to Temporary replacement.
     */
    private function lockPredecessor(Employee $employee, array $data, ?int $companyId): ?EmployeeWorkAssignment
    {
        if (empty($data['replaces_assignment_id'])) {
            return null;
        }

        $predecessor = EmployeeWorkAssignment::query()->lockForUpdate()->findOrFail($data['replaces_assignment_id']);

        PositionSuccession::assertCanFollow($predecessor, $employee->id, CarbonImmutable::parse($data['start_date']));

        if ($companyId !== null && $predecessor->client_company_id !== null && $predecessor->client_company_id !== $companyId) {
            $this->fail('The assignment being replaced belongs to a different company.', 'SUCCESSION_DIFFERENT_COMPANY');
        }

        return $predecessor;
    }

    private function guardAgainstDuplicates(Employee $employee, ?WorkLocation $workLocation, ?int $companyId): void
    {
        $active = $employee->workAssignments()->where('status', WorkAssignmentStatus::Active);

        if ($workLocation !== null && (clone $active)->where('work_location_id', $workLocation->id)->exists()) {
            $this->fail(
                "Employee '{$employee->full_name}' already has an active assignment at this work location.",
                'EMPLOYEE_ALREADY_ASSIGNED_AT_LOCATION',
            );
        }

        if ($companyId === null) {
            return;
        }

        $sameCompany = (clone $active)->where('client_company_id', $companyId);
        $conflicts = $workLocation === null
            ? $sameCompany->exists()
            : $sameCompany->whereNull('work_location_id')->exists();

        if ($conflicts) {
            $this->fail(
                "Employee '{$employee->full_name}' already has an active assignment at this company without a specific work location. Complete that assignment's location instead of creating another.",
                'EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY',
            );
        }
    }

    private function guardCapacity(?WorkLocation $workLocation, array $data, Admin $actor): void
    {
        if ($workLocation === null || $workLocation->capacity === null) {
            return;
        }

        $activeCount = $workLocation->workAssignments()->where('status', WorkAssignmentStatus::Active)->count();
        $overriding = $actor->role === AdminRole::SuperAdmin && ($data['override_capacity'] ?? false) === true;

        if ($activeCount >= $workLocation->capacity && ! $overriding) {
            $this->fail(
                "This work location has reached its approved capacity ({$workLocation->capacity}).",
                'WORK_LOCATION_CAPACITY_EXCEEDED',
            );
        }
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
