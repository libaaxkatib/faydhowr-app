<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentSalaryHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use App\Support\Hr\CompanyLocationResolver;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollPeriodLock;
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
    public function __construct(private readonly ChangeAssignmentSalaryAction $salary, private readonly PayrollPeriodLock $lock = new PayrollPeriodLock) {}

    public function handle(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        if (! empty($data['work_location_id'])) {
            $this->completeLocation($assignment, (int) $data['work_location_id'], $data, $actor);
        }

        $this->guardSuccession($assignment, $data);
        $this->guardPayrollDates($assignment, $data);

        $before = [
            'position_id' => $assignment->position_id,
            'assignment_type' => $assignment->assignment_type?->value ?? AssignmentType::Permanent->value,
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'notes' => $assignment->notes,
        ];
        $oldStart = $assignment->start_date?->toDateString();

        $this->applySalaryChange($assignment, $data, $actor);

        $assignment->update([
            'position_id' => array_key_exists('position_id', $data) ? $data['position_id'] : $assignment->position_id,
            'assignment_type' => $data['assignment_type'] ?? $assignment->assignment_type?->value ?? AssignmentType::Permanent->value,
            'start_date' => $data['start_date'] ?? $assignment->start_date,
            'end_date' => $data['end_date'] ?? $assignment->end_date,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $assignment->notes,
        ]);

        $this->moveFirstSalaryRowWithTheStart($assignment, $oldStart);

        $after = [
            'position_id' => $assignment->position_id,
            'assignment_type' => $assignment->assignment_type->value,
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'notes' => $assignment->notes,
        ];
        $changed = array_keys(array_filter($after, fn ($value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Work assignment #{$assignment->id} updated.",
            entityType: EmployeeWorkAssignment::class,
            entityId: $assignment->id,
            // The original values are kept here, so a changed start date, position or type can always be traced back.
            metadata: ['event' => 'edited', 'changed_fields' => $changed, 'before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed))],
        ));

        return $assignment->load(['workLocation.clientCompany', 'clientCompany', 'position']);
    }

    /**
     * Moving a start or last day changes the payable days of every payroll month from the earlier of the old and new date onward.
     * That may not reach into an approved or finalized month.
     */
    private function guardPayrollDates(EmployeeWorkAssignment $assignment, array $data): void
    {
        foreach (['start_date', 'end_date'] as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $old = $assignment->{$field}?->toDateString();
            $new = CarbonImmutable::parse($data[$field])->toDateString();

            if ($old === $new) {
                continue;
            }

            $this->lock->assertAssignmentDateChangeAllowed($old === null ? $new : min($old, $new));
        }
    }

    /**
     * The first salary row starts with the assignment. When the start date moves, that row moves with it, so payroll never finds
     * a day without a salary. A start date that would jump over a LATER salary change is refused.
     */
    private function moveFirstSalaryRowWithTheStart(EmployeeWorkAssignment $assignment, ?string $oldStart): void
    {
        $newStart = $assignment->start_date?->toDateString();

        if ($oldStart === null || $newStart === null || $oldStart === $newStart) {
            return;
        }

        $rows = AssignmentSalaryHistory::query()->where('work_assignment_id', $assignment->id)->orderBy('effective_from')->get();
        $first = $rows->first();

        if ($first === null || $first->effective_from->toDateString() !== $oldStart) {
            return;
        }

        if ($rows->skip(1)->contains(fn ($row): bool => $row->effective_from->toDateString() <= $newStart)) {
            $this->fail('The new start date is on or after a later salary change. Move the start date to before that change, or correct the salary history first.', 'START_DATE_PASSES_SALARY_CHANGE');
        }

        $first->update(['effective_from' => $newStart]);
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
