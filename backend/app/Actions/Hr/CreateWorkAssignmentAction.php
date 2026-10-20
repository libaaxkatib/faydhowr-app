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
use App\Support\Hr\AssignmentChain;
use App\Support\Hr\CompanyLocationResolver;
use App\Support\Hr\ConcurrentAssignmentGuard;
use App\Support\Hr\EmployeeRegistrationWorkflow;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\Payroll\PayrollMoney;
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
    public function __construct(private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection) {}

    /**
     * An assignment that starts inside an approved or finalized payroll month changes what that month should have paid, so it is a
     * correction (Super Admin only, with `correction_reason`) - see ClosedPayrollCorrection. Anything else is created as before.
     *
     * A REPLACEMENT is different from every other assignment: it has no salary of its own. It must name the assignment it replaces
     * (`replaces_assignment_id`) and its salary basis is the monthly salary of the Permanent employee whose position it takes over,
     * copied (with the date and the source assignment recorded) so the calculation can always be reproduced.
     */
    public function handle(Employee $employee, array $data, Admin $actor): EmployeeWorkAssignment
    {
        // Direct API too: a new registration that has not completed the registration workflow gets no assignment at all.
        (new EmployeeRegistrationWorkflow)->requireEligible($employee, 'cannot be given a work assignment', 'EMPLOYEE_REGISTRATION_INCOMPLETE');

        if (($data['assignment_type'] ?? AssignmentType::Permanent->value) === AssignmentType::Replacement->value) {
            $data = $this->withInheritedSalary($employee, $data);

            // HR was warned that this person is a Temporary worker and chose to go on: the decision is kept (who, when) in the audit history.
            if ($employee->employment_type === 'temporary') {
                $data['temporary_confirmation'] = ['confirmed_by_admin_id' => $actor->id, 'confirmed_at' => now()->toIso8601String(), 'employee_id' => $employee->id, 'employment_type' => 'temporary'];
            }
        }

        $chainEmployees = ! empty($data['replaces_assignment_id'])
            ? AssignmentChain::employeeIds(EmployeeWorkAssignment::query()->findOrFail($data['replaces_assignment_id']))
            : [];

        return $this->corrections->run(
            $actor,
            'assigned',
            [$employee->id, ...$chainEmployees],
            CarbonImmutable::parse($data['start_date'])->toDateString(),
            $data['correction_reason'] ?? null,
            ['assignment_id' => null, 'old' => null, 'new' => array_intersect_key($data, array_flip(['start_date', 'end_date', 'assignment_type', 'salary_amount', 'salary_currency', 'replaces_assignment_id']))],
            fn (): EmployeeWorkAssignment => $this->create($employee, $data, $actor),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withInheritedSalary(Employee $employee, array $data): array
    {
        if (empty($data['replaces_assignment_id'])) {
            $this->fail('A Replacement takes over the position of a Permanent employee. Use Replace Employee and choose the assignment being replaced.', 'REPLACEMENT_NEEDS_REPLACED_ASSIGNMENT');
        }

        if ($employee->employment_type === 'temporary' && ($data['acknowledge_temporary_as_replacement'] ?? false) !== true) {
            $this->fail("{$employee->full_name} ({$employee->employee_number}) is a Temporary employee. Temporary cover is recorded as a Temporary replacement (paid per verified day), not as a Replacement who takes over the position. Correct the assignment, or confirm that you really want a Replacement for this person.", 'TEMPORARY_REGISTERED_AS_REPLACEMENT');
        }

        $predecessor = EmployeeWorkAssignment::query()->findOrFail($data['replaces_assignment_id']);
        $basis = AssignmentChain::inheritedSalary($predecessor, CarbonImmutable::parse($data['start_date']));

        if ($basis === null) {
            $this->fail('The Permanent assignment being replaced has no salary to inherit.', 'REPLACEMENT_NO_SALARY_BASIS');
        }

        $typed = isset($data['salary_amount']) && (PayrollMoney::toCents($data['salary_amount']) !== PayrollMoney::toCents($basis['amount'])
            || strtoupper((string) ($data['salary_currency'] ?? $basis['currency'])) !== strtoupper($basis['currency']));

        if ($typed) {
            $this->fail("A Replacement's salary is the Permanent employee's salary ({$basis['amount']} {$basis['currency']} per month) and cannot be entered separately.", 'REPLACEMENT_SALARY_IS_INHERITED');
        }

        return [
            ...$data,
            'salary_amount' => $basis['amount'],
            'salary_currency' => $basis['currency'],
            'salary_frequency' => $basis['frequency'],
            'salary_basis' => $basis,
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function create(Employee $employee, array $data, Admin $actor): EmployeeWorkAssignment
    {
        $workLocation = ! empty($data['work_location_id'])
            ? WorkLocation::query()->findOrFail($data['work_location_id'])
            : null;

        $companyId = CompanyLocationResolver::resolve($workLocation, $data['client_company_id'] ?? null);

        $this->guardAgainstDuplicates($employee, $workLocation, $companyId);
        ConcurrentAssignmentGuard::assertAllowed($employee, $companyId, CarbonImmutable::parse($data['start_date'])->startOfDay(), empty($data['end_date']) ? null : CarbonImmutable::parse($data['end_date'])->startOfDay());
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
                'reason' => isset($data['salary_basis'])
                    ? "Inherited from Permanent assignment #{$data['salary_basis']['assignment_id']} (salary row effective {$data['salary_basis']['effective_from']}): a Replacement has no salary of its own"
                    : 'Initial salary',
                'changed_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Work assignment created for employee '{$employee->full_name}' ({$employee->employee_number}).".($predecessor ? " It takes over assignment #{$predecessor->id}." : ''),
                entityType: EmployeeWorkAssignment::class,
                entityId: $assignment->id,
                metadata: [
                    ...($predecessor === null ? [] : [
                        'replaces_assignment_id' => $predecessor->id,
                        'predecessor_last_paid_day' => $predecessor->end_date->toDateString(),
                        'uncovered_gap_days' => PositionSuccession::gapDays($predecessor->end_date, $assignment->start_date),
                    ]),
                    ...(isset($data['salary_basis']) ? ['salary_basis' => $data['salary_basis']] : []),
                    ...(isset($data['temporary_confirmation']) ? ['temporary_registered_as_replacement_confirmed' => $data['temporary_confirmation']] : []),
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
