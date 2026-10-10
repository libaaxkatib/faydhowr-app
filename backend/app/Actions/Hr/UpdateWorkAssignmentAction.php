<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use App\Support\Hr\CompanyLocationResolver;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Edits position/dates/notes on an existing assignment. A work location can be
 * COMPLETED later when the assignment was created with only a company (it is
 * optional), but an existing location is never swapped in place: moving to a
 * different location is a new assignment (via CreateWorkAssignmentAction), so
 * history is never silently rewritten. The employee and company never change.
 *
 * Salary amount/currency/frequency changes are delegated to
 * ChangeAssignmentSalaryAction so salary history is preserved (an optional
 * salary_effective_from defaults to today).
 */
class UpdateWorkAssignmentAction
{
    public function __construct(private readonly ChangeAssignmentSalaryAction $salary) {}

    public function handle(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        if (! empty($data['work_location_id'])) {
            $this->completeLocation($assignment, (int) $data['work_location_id'], $data, $actor);
        }

        $this->guardSuccession($assignment, $data);

        $this->applySalaryChange($assignment, $data, $actor);

        $assignment->update([
            'position_id' => array_key_exists('position_id', $data) ? $data['position_id'] : $assignment->position_id,
            'start_date' => $data['start_date'] ?? $assignment->start_date,
            'end_date' => $data['end_date'] ?? $assignment->end_date,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $assignment->notes,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Work assignment #{$assignment->id} updated.",
            entityType: EmployeeWorkAssignment::class,
            entityId: $assignment->id,
        ));

        return $assignment->load(['workLocation.clientCompany', 'clientCompany', 'position']);
    }

    /**
     * Moving the dates must not create overlap (two people paid for one
     * calendar day) with the assignment this one took over from, or with the one
     * that took over from it.
     */
    private function guardSuccession(EmployeeWorkAssignment $assignment, array $data): void
    {
        if (! empty($data['end_date'])) {
            PositionSuccession::assertEndKeepsSuccessorClear($assignment, CarbonImmutable::parse($data['end_date']));
        }

        if (! empty($data['start_date']) && $assignment->replaces_assignment_id !== null) {
            PositionSuccession::assertCanFollow(
                EmployeeWorkAssignment::query()->findOrFail($assignment->replaces_assignment_id),
                $assignment->employee_id,
                CarbonImmutable::parse($data['start_date']),
                $assignment->id,
            );
        }
    }

    private function applySalaryChange(EmployeeWorkAssignment $assignment, array $data, Admin $actor): void
    {
        $amount = (string) ($data['salary_amount'] ?? $assignment->salary_amount);
        $currency = $data['salary_currency'] ?? $assignment->salary_currency;
        $frequency = (string) ($data['salary_frequency'] ?? $assignment->salary_frequency->value);

        $unchanged = PayrollMoney::toCents($amount) === PayrollMoney::toCents($assignment->salary_amount)
            && $currency === $assignment->salary_currency
            && $frequency === $assignment->salary_frequency->value;

        if ($unchanged && empty($data['salary_effective_from'])) {
            return;
        }

        $this->salary->handle(
            $assignment,
            $amount,
            $currency,
            $frequency,
            $data['salary_effective_from'] ?? CarbonImmutable::now()->toDateString(),
            $data['salary_change_reason'] ?? null,
            $actor,
        );
    }

    private function completeLocation(EmployeeWorkAssignment $assignment, int $locationId, array $data, Admin $actor): void
    {
        if ($assignment->work_location_id !== null) {
            if ((int) $assignment->work_location_id === $locationId) {
                return;
            }

            $this->fail('This assignment already has a work location. Moving to another location is a new assignment.', 'ASSIGNMENT_LOCATION_ALREADY_SET');
        }

        $location = WorkLocation::query()->findOrFail($locationId);

        CompanyLocationResolver::assertCompletes($location, $assignment->client_company_id);

        $alreadyThere = EmployeeWorkAssignment::query()
            ->where('employee_id', $assignment->employee_id)
            ->where('work_location_id', $location->id)
            ->where('status', WorkAssignmentStatus::Active)
            ->where('id', '!=', $assignment->id)
            ->exists();

        if ($alreadyThere) {
            $this->fail('The employee already has an active assignment at this work location.', 'EMPLOYEE_ALREADY_ASSIGNED_AT_LOCATION');
        }

        if ($location->capacity !== null) {
            $activeCount = $location->workAssignments()->where('status', WorkAssignmentStatus::Active)->count();
            $overriding = $actor->role === AdminRole::SuperAdmin && ($data['override_capacity'] ?? false) === true;

            if ($activeCount >= $location->capacity && ! $overriding) {
                $this->fail("This work location has reached its approved capacity ({$location->capacity}).", 'WORK_LOCATION_CAPACITY_EXCEEDED');
            }
        }

        $assignment->update(['work_location_id' => $location->id]);
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
