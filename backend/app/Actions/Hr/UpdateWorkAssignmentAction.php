<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AssignmentShift;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentSalaryHistory;
use App\Models\AssignmentSuccession;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use App\Support\Hr\AssignmentChain;
use App\Support\Hr\CompanyLocationResolver;
use App\Support\Hr\ConcurrentAssignmentGuard;
use App\Support\Hr\CoverConflicts;
use App\Support\Hr\Payroll\ClosedPayrollCorrection;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\PermanentSuccession;
use App\Support\Hr\PositionSuccession;
use App\Support\Hr\UnresolvedTemporaryReplacements;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private readonly ChangeAssignmentSalaryAction $salary, private readonly ClosedPayrollCorrection $corrections = new ClosedPayrollCorrection) {}

    /**
     * A change that would alter an approved or finalized payroll month is a correction: only a Super Admin may make it, with a reason
     * (`correction_reason`), and the closed month is never edited (see ClosedPayrollCorrection). A change that leaves every closed
     * month as it was is allowed as before.
     */
    public function handle(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        $this->guardTypeAndInheritedSalary($assignment, $data);

        $plan = $this->successionPlan($assignment, $data);
        $earliest = $this->earliestPayrollDate($assignment, $data);

        if ($earliest === null) {
            return $this->apply($assignment, $data, $actor, $plan);
        }

        return $this->corrections->run(
            $actor,
            'edited',
            [...AssignmentChain::employeeIds($assignment), ...($plan === null ? [] : [$plan['employee']->id])],
            $earliest,
            $data['correction_reason'] ?? null,
            [
                'assignment_id' => $assignment->id,
                'old' => ['start_date' => $assignment->start_date?->toDateString(), 'end_date' => $assignment->end_date?->toDateString(), 'assignment_type' => $assignment->assignment_type?->value, 'salary_amount' => (string) $assignment->salary_amount, 'salary_currency' => $assignment->salary_currency],
                'new' => array_intersect_key($data, array_flip(['start_date', 'end_date', 'assignment_type', 'salary_amount', 'salary_currency', 'salary_effective_from'])),
            ],
            fn (): EmployeeWorkAssignment => $this->apply($assignment, $data, $actor, $plan),
        );
    }

    /**
     * Setting an end date on an open Permanent assignment, or moving it EARLIER, is a removal: it needs the replacement choice
     * (docs/HR_PAYROLL_RULES.md N1). Moving the end date later, or editing anything else, needs none.
     *
     * @return array<string, mixed>|null
     */
    private function successionPlan(EmployeeWorkAssignment $assignment, array $data): ?array
    {
        if (! array_key_exists('end_date', $data) || $data['end_date'] === null) {
            return null;
        }

        $newEnd = CarbonImmutable::parse($data['end_date'])->startOfDay();
        $oldEnd = $assignment->end_date;

        if ($oldEnd !== null && ! $newEnd->lt(CarbonImmutable::parse($oldEnd)->startOfDay())) {
            return null;
        }

        return PermanentSuccession::demand($assignment, $data['successor'] ?? null, $newEnd);
    }

    /**
     * A Replacement's salary basis is the replaced Permanent's salary and is not typed in: it cannot be edited here, and an
     * assignment cannot be turned into a Replacement by editing it (use Replace Employee, which links it to the one it replaces).
     */
    private function guardTypeAndInheritedSalary(EmployeeWorkAssignment $assignment, array $data): void
    {
        $current = $assignment->assignment_type ?? AssignmentType::Permanent;
        $requested = isset($data['assignment_type']) ? AssignmentType::from($data['assignment_type']) : $current;

        if ($requested === AssignmentType::Replacement && $current !== AssignmentType::Replacement) {
            $this->fail('An assignment becomes a Replacement only through Replace Employee, which links it to the Permanent assignment it replaces and inherits that salary.', 'USE_REPLACE_EMPLOYEE');
        }

        if ($current === AssignmentType::Replacement && $requested === AssignmentType::Replacement) {
            $changesSalary = (isset($data['salary_amount']) && PayrollMoney::toCents($data['salary_amount']) !== PayrollMoney::toCents($assignment->salary_amount))
                || (isset($data['salary_currency']) && strtoupper((string) $data['salary_currency']) !== strtoupper((string) $assignment->salary_currency))
                || (isset($data['salary_frequency']) && $data['salary_frequency'] !== $assignment->salary_frequency->value);

            if ($changesSalary) {
                $this->fail("A Replacement's salary is inherited from the Permanent employee whose position it takes over and cannot be edited here. Change the salary of that Permanent assignment instead: the new salary then applies to this Replacement for the whole month of the change. Or make this assignment Permanent first.", 'REPLACEMENT_SALARY_IS_INHERITED');
            }
        }
    }

    /** The first calendar date this edit can change pay for, or null when it cannot change pay at all. */
    private function earliestPayrollDate(EmployeeWorkAssignment $assignment, array $data): ?string
    {
        $dates = [];

        if (! empty($data['start_date'])) {
            $old = $assignment->start_date?->toDateString();
            $new = CarbonImmutable::parse($data['start_date'])->toDateString();

            if ($old !== $new) {
                $dates[] = $old === null ? $new : min($old, $new);
            }
        }

        if (! empty($data['end_date'])) {
            $old = $assignment->end_date?->toDateString();
            $new = CarbonImmutable::parse($data['end_date'])->toDateString();

            if ($old !== $new) {
                $dates[] = CarbonImmutable::parse($old === null ? $new : min($old, $new))->addDay()->toDateString();
            }
        }

        if (isset($data['assignment_type']) && $data['assignment_type'] !== ($assignment->assignment_type?->value ?? AssignmentType::Permanent->value)) {
            $dates[] = $assignment->start_date->toDateString();
        }

        $salaryChanges = (isset($data['salary_amount']) && PayrollMoney::toCents($data['salary_amount']) !== PayrollMoney::toCents($assignment->salary_amount))
            || (isset($data['salary_currency']) && strtoupper((string) $data['salary_currency']) !== strtoupper((string) $assignment->salary_currency))
            || (isset($data['salary_frequency']) && $data['salary_frequency'] !== $assignment->salary_frequency->value)
            || ! empty($data['salary_effective_from']);

        if ($salaryChanges) {
            // A Permanent salary change reaches any Replacement for the WHOLE month it takes effect in, so the first day of that month counts.
            $dates[] = CarbonImmutable::parse($data['salary_effective_from'] ?? CarbonImmutable::now())->startOfMonth()->toDateString();
        }

        return $dates === [] ? null : min($dates);
    }

    /** @param  array<string, mixed>|null  $plan  the validated replacement plan when this edit removes a Permanent assignment */
    private function apply(EmployeeWorkAssignment $assignment, array $data, Admin $actor, ?array $plan = null): EmployeeWorkAssignment
    {
        if ($plan === null) {
            return $this->applyChanges($assignment, $data, $actor);
        }

        return DB::transaction(function () use ($assignment, $data, $actor, $plan): EmployeeWorkAssignment {
            $updated = $this->applyChanges($assignment, $data, $actor);
            PermanentSuccession::complete($assignment->refresh(), $plan, $actor, AssignmentSuccession::TRIGGER_EDIT_END_DATE, $data['correction_reason'] ?? null);

            return $updated;
        });
    }

    private function applyChanges(EmployeeWorkAssignment $assignment, array $data, Admin $actor): EmployeeWorkAssignment
    {
        if (! empty($data['work_location_id'])) {
            $this->completeLocation($assignment, (int) $data['work_location_id'], $data, $actor);
        }

        $this->guardSuccession($assignment, $data);
        $this->guardConcurrency($assignment, $data);
        $this->guardCovers($assignment, $data);

        $wasUnresolved = UnresolvedTemporaryReplacements::isUnresolved($assignment);

        $before = [
            'position_id' => $assignment->position_id,
            'assignment_type' => $assignment->assignment_type?->value ?? AssignmentType::Permanent->value,
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'notes' => $assignment->notes,
            'shift' => $assignment->shift?->value,
        ];
        $oldStart = $assignment->start_date?->toDateString();

        $this->applySalaryChange($assignment, $data, $actor);

        $assignment->update([
            'position_id' => array_key_exists('position_id', $data) ? $data['position_id'] : $assignment->position_id,
            'assignment_type' => $data['assignment_type'] ?? $assignment->assignment_type?->value ?? AssignmentType::Permanent->value,
            'start_date' => $data['start_date'] ?? $assignment->start_date,
            'end_date' => $data['end_date'] ?? $assignment->end_date,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $assignment->notes,
            // The shift is information only: editing it never touches salary, dates, type or company.
            'shift' => array_key_exists('shift', $data) ? $data['shift'] : $assignment->shift?->value,
        ]);

        $this->moveFirstSalaryRowWithTheStart($assignment, $oldStart);

        $after = [
            'position_id' => $assignment->position_id,
            'assignment_type' => $assignment->assignment_type->value,
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'notes' => $assignment->notes,
            'shift' => $assignment->shift?->value,
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

        if ($wasUnresolved && ! UnresolvedTemporaryReplacements::isUnresolved($assignment)) {
            UnresolvedTemporaryReplacements::auditResolved($assignment, 'assignment_type_changed', $actor, ['assignment_type_from' => 'replacement', 'assignment_type_to' => $assignment->assignment_type->value]);
        }

        return $assignment->load(['workLocation.clientCompany', 'clientCompany', 'position']);
    }

    /** Moving the dates may not make this assignment overlap another assignment of the same employee (unless multi-company is on). */
    private function guardConcurrency(EmployeeWorkAssignment $assignment, array $data): void
    {
        if (! array_key_exists('start_date', $data) && ! array_key_exists('end_date', $data)) {
            return;
        }

        $start = CarbonImmutable::parse($data['start_date'] ?? $assignment->start_date)->startOfDay();
        $endValue = $data['end_date'] ?? $assignment->end_date;

        ConcurrentAssignmentGuard::assertAllowed($assignment->employee, $assignment->client_company_id, $start, $endValue === null ? null : CarbonImmutable::parse($endValue)->startOfDay(), $assignment->id);
    }

    /**
     * Changing the dates or the shift of an assignment must not make it overlap a Temporary cover of its holder (U1), nor make the covers it
     * carries overlap each other (U12). Only a real change is checked; the edited assignment is never compared with itself.
     */
    private function guardCovers(EmployeeWorkAssignment $assignment, array $data): void
    {
        $start = CarbonImmutable::parse($data['start_date'] ?? $assignment->start_date)->toDateString();
        $endValue = array_key_exists('end_date', $data) && $data['end_date'] !== null ? $data['end_date'] : $assignment->end_date;
        $end = $endValue === null ? null : CarbonImmutable::parse($endValue)->toDateString();
        $shift = array_key_exists('shift', $data) ? AssignmentShift::tryFrom((string) ($data['shift'] ?? '')) : $assignment->shift;

        $unchanged = $start === $assignment->start_date?->toDateString() && $end === $assignment->end_date?->toDateString() && $shift === $assignment->shift;

        if ($unchanged) {
            return;
        }

        CoverConflicts::assertOwnAssignmentFree($assignment->employee, $start, $end, $shift);

        $probe = $assignment->replicate();
        $probe->id = $assignment->id;
        $probe->start_date = $start;
        $probe->end_date = $end;
        $probe->shift = $shift;
        CoverConflicts::assertCoversStillFree($probe);
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
