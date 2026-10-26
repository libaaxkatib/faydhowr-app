<?php

namespace App\Support\Hr;

use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Actions\Hr\UpdateEmployeeStatusAction;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Enums\SalaryFrequency;
use App\Enums\TemporaryReplacementStatus;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentSuccession;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * docs/HR_PAYROLL_RULES.md N1, N2, N3: when a PERMANENT assignment is removed, HR/Management must choose who takes the position, and nothing else is
 * offered and nobody is picked automatically. The two permitted choices are
 *
 *   temporary     an existing Temporary employee who has been covering that very assignment. He or she keeps the same employee record and becomes
 *                 Permanent; the new Permanent assignment inherits the departing Permanent's salary (nothing is typed or confirmed);
 *   waiting_list  a person on the HR Waiting List. The existing rules apply unchanged: the status change Waiting -> Active goes through
 *                 UpdateEmployeeStatusAction (a new registration that has not completed the registration workflow is refused there exactly as
 *                 anywhere else), the new Permanent assignment goes through CreateWorkAssignmentAction, and the salary is the one HR enters
 *                 (the existing rule for a Permanent successor; it is never assumed to be inherited).
 *
 * The new Permanent assignment starts on the EFFECTIVE DATE HR selects, INCLUSIVE (U9): that day is part of the new salary calculation and is never
 * shifted. When none is given it is the day after the departing assignment's last paid day. It must be after that last paid day (the end date is the
 * last paid day, inclusive; a successor may not start on or before it); an earlier or equal date is refused, never silently moved. The employee's
 * employment type changes as soon as the selection is saved; the assignment and its salary begin only on the effective date. Everything - ending,
 * the type change, the appointment, the new assignment and the
 * record in assignment_successions - happens in the caller's transaction, so a failure leaves nothing half done.
 *
 * Not covered, on purpose: a Replacement-type assignment (the rule is about Permanent), a cancelled mistake (CancelWorkAssignmentAction refuses once
 * the assignment has payroll or replacements), and Replace Employee (it already names the successor in the same action).
 */
final class PermanentSuccession
{
    /** A Permanent assignment that is still open and has no recorded successor needs a choice before it can be removed. */
    public static function isRequired(EmployeeWorkAssignment $assignment): bool
    {
        if (($assignment->assignment_type?->value ?? 'permanent') !== AssignmentType::Permanent->value) {
            return false;
        }

        if ($assignment->status !== WorkAssignmentStatus::Active) {
            return false;
        }

        // A position whose successor is already named - by an earlier choice, or by an assignment that replaces this one (Replace Employee,
        // manual succession) - is resolved: its replacement is part of the assignment history.
        if (self::recorded($assignment) instanceof AssignmentSuccession) {
            return false;
        }

        return ! EmployeeWorkAssignment::query()
            ->where('replaces_assignment_id', $assignment->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->exists();
    }

    public static function recorded(EmployeeWorkAssignment $assignment): ?AssignmentSuccession
    {
        return AssignmentSuccession::query()->where('departing_assignment_id', $assignment->id)->first();
    }

    /**
     * Returns the validated plan for ending the assignment on $lastDay, or null when no choice is needed. Throws 422 when a choice is needed
     * and missing or not one of the two permitted kinds. Nothing is written.
     *
     * @param  array<string, mixed>|null  $choice
     * @return array{type: string, employee: Employee, cover: ?TemporaryReplacement, salary: array{amount: string, currency: string, frequency: string, source: string}, last_day: CarbonImmutable, start: CarbonImmutable, effective_entered: bool}|null
     */
    public static function demand(EmployeeWorkAssignment $assignment, ?array $choice, CarbonImmutable $lastDay): ?array
    {
        if (! self::isRequired($assignment)) {
            return null;
        }

        if ($choice === null || $choice === [] || empty($choice['type']) || empty($choice['employee_id'])) {
            self::fail(
                'Choose who takes over this Permanent position before it is removed: a Temporary employee who has been covering it, or a person from the HR Waiting List.',
                'SUCCESSOR_REQUIRED',
                ['assignment_id' => $assignment->id],
            );
        }

        return self::plan($assignment, $choice, $lastDay);
    }

    /**
     * @param  array<string, mixed>  $choice
     * @return array{type: string, employee: Employee, cover: ?TemporaryReplacement, salary: array{amount: string, currency: string, frequency: string, source: string}, last_day: CarbonImmutable, start: CarbonImmutable, effective_entered: bool}
     */
    private static function plan(EmployeeWorkAssignment $assignment, array $choice, CarbonImmutable $lastDay): array
    {
        $type = (string) $choice['type'];

        if (! in_array($type, AssignmentSuccession::types(), true)) {
            self::fail('The replacement must be a Temporary employee who covered this position or a person from the HR Waiting List.', 'SUCCESSOR_TYPE_INVALID');
        }

        if ($lastDay->lt(CarbonImmutable::parse($assignment->start_date)->startOfDay())) {
            self::fail("The last day ({$lastDay->toDateString()}) is before the assignment started ({$assignment->start_date->toDateString()}).", 'SUCCESSOR_LAST_DAY_BEFORE_START');
        }

        $employee = Employee::query()->find((int) $choice['employee_id']);

        if ($employee === null) {
            self::fail('The chosen replacement was not found among the active HR records.', 'SUCCESSOR_NOT_FOUND');
        }

        if ((int) $employee->id === (int) $assignment->employee_id) {
            self::fail('An employee cannot be their own replacement.', 'SUCCESSOR_SAME_EMPLOYEE');
        }

        $effective = null;

        if (! empty($choice['effective_date'])) {
            try {
                $effective = CarbonImmutable::parse((string) $choice['effective_date'])->startOfDay();
            } catch (\Throwable) {
                self::fail('The effective date of the replacement is not a valid date.', 'SUCCESSOR_EFFECTIVE_DATE_INVALID');
            }

            if ($effective->lte($lastDay)) {
                self::fail(
                    "The replacement's effective date ({$effective->toDateString()}) must be after the departing employee's last paid day ({$lastDay->toDateString()}). Both dates are inclusive, so the same day cannot be paid to two people; nothing was moved automatically.",
                    'SUCCESSOR_EFFECTIVE_DATE_NOT_AFTER_LAST_DAY',
                );
            }
        }

        $cover = null;

        if ($type === AssignmentSuccession::TYPE_TEMPORARY) {
            if ($employee->employment_type !== 'temporary' || $employee->status !== EmployeeStatus::Active) {
                self::fail("{$employee->full_name} ({$employee->employee_number}) is not an active Temporary employee.", 'SUCCESSOR_NOT_TEMPORARY');
            }

            $cover = TemporaryReplacement::query()
                ->where('work_assignment_id', $assignment->id)
                ->where('replacement_employee_id', $employee->id)
                ->orderByDesc('id')
                ->first();

            if ($cover === null) {
                self::fail("{$employee->full_name} ({$employee->employee_number}) has not been covering this position. Choose a Temporary employee who has, or a person from the Waiting List.", 'SUCCESSOR_NOT_COVERING');
            }

            $salary = self::departingSalary($assignment, $lastDay);

            if ($salary === null) {
                self::fail('The departing assignment has no salary to inherit.', 'SUCCESSOR_NO_SALARY_TO_INHERIT');
            }
        } else {
            if ($employee->status !== EmployeeStatus::Waiting) {
                self::fail("{$employee->full_name} ({$employee->employee_number}) is not on the HR Waiting List (status: {$employee->status->value}).", 'SUCCESSOR_NOT_WAITING');
            }

            $amount = $choice['salary_amount'] ?? null;
            $currency = strtoupper((string) ($choice['salary_currency'] ?? ''));

            if (! is_numeric($amount) || (float) $amount <= 0 || strlen($currency) !== 3) {
                self::fail('A person from the Waiting List needs the salary HR agrees for the position (amount and currency).', 'SUCCESSOR_SALARY_REQUIRED');
            }

            $frequency = (string) ($choice['salary_frequency'] ?? SalaryFrequency::Monthly->value);

            if (! in_array($frequency, SalaryFrequency::values(), true)) {
                self::fail('The salary frequency is not valid.', 'SUCCESSOR_SALARY_FREQUENCY_INVALID');
            }

            $salary = ['amount' => number_format((float) $amount, 2, '.', ''), 'currency' => $currency, 'frequency' => $frequency, 'source' => 'entered'];
        }

        return [
            'type' => $type,
            'employee' => $employee,
            'cover' => $cover,
            'salary' => $salary,
            'last_day' => $lastDay,
            'start' => $effective ?? $lastDay->addDay(),
            'effective_entered' => $effective !== null,
        ];
    }

    /**
     * The departing Permanent's salary on its last paid day (the salary row in effect that day; the first row when the assignment ended before
     * any recorded change). The Replacement month-wide rule does NOT apply here: this is a Permanent successor.
     *
     * @return array{amount: string, currency: string, frequency: string, source: string}|null
     */
    private static function departingSalary(EmployeeWorkAssignment $assignment, CarbonImmutable $lastDay): ?array
    {
        $rows = $assignment->salaryHistories()->get();
        $row = $rows->filter(fn ($r) => $r->effective_from->lte($lastDay))->last() ?? $rows->first();

        if ($row !== null) {
            return ['amount' => (string) $row->amount, 'currency' => $row->currency, 'frequency' => $row->frequency->value, 'source' => 'inherited'];
        }

        if ($assignment->salary_amount === null || $assignment->salary_currency === null) {
            return null;
        }

        return ['amount' => (string) $assignment->salary_amount, 'currency' => $assignment->salary_currency, 'frequency' => $assignment->salary_frequency->value, 'source' => 'inherited'];
    }

    /**
     * Carries out a validated plan after the departing assignment has been ended (its end_date is set). Runs in the caller's transaction.
     *
     * @param  array{type: string, employee: Employee, cover: ?TemporaryReplacement, salary: array{amount: string, currency: string, frequency: string, source: string}, last_day: CarbonImmutable, start: CarbonImmutable, effective_entered: bool}  $plan
     */
    public static function complete(EmployeeWorkAssignment $departing, array $plan, Admin $actor, string $trigger, ?string $correctionReason = null): AssignmentSuccession
    {
        return DB::transaction(function () use ($departing, $plan, $actor, $trigger, $correctionReason): AssignmentSuccession {
            $employee = Employee::query()->whereKey($plan['employee']->id)->lockForUpdate()->firstOrFail();
            $typeFrom = $employee->employment_type;
            $typeTo = $typeFrom;
            $endedCovers = [];

            if ($plan['type'] === AssignmentSuccession::TYPE_TEMPORARY) {
                // The cover on the departing position ends with it; the person keeps one employee record and becomes Permanent.
                $covers = TemporaryReplacement::query()
                    ->where('work_assignment_id', $departing->id)
                    ->where('replacement_employee_id', $employee->id)
                    ->where('status', TemporaryReplacementStatus::Active)
                    ->get();

                foreach ($covers as $cover) {
                    $end = $plan['last_day']->lt(CarbonImmutable::parse($cover->start_date)) ? CarbonImmutable::parse($cover->start_date) : $plan['last_day'];
                    $cover->endOn($end->toDateString());
                    $endedCovers[] = $cover->id;
                }

                $employee->update(['employment_type' => 'permanent']);
                $typeTo = 'permanent';
            } else {
                // The existing status workflow decides whether this person may become Active (registration workflow, permissions, history).
                app(UpdateEmployeeStatusAction::class)->handle(
                    $employee,
                    EmployeeStatus::Active,
                    "Appointed from the HR Waiting List to take over assignment #{$departing->id} from {$plan['start']->toDateString()}.",
                    $actor,
                );
                $employee->refresh();
            }

            $new = app(CreateWorkAssignmentAction::class)->handle($employee, [
                'client_company_id' => $departing->client_company_id,
                'work_location_id' => $departing->work_location_id,
                'replaces_assignment_id' => $departing->id,
                'position_id' => $departing->position_id,
                'assignment_type' => AssignmentType::Permanent->value,
                'shift' => $departing->shift?->value,
                'start_date' => $plan['start']->toDateString(),
                'salary_amount' => $plan['salary']['amount'],
                'salary_currency' => $plan['salary']['currency'],
                'salary_frequency' => $plan['salary']['frequency'],
                'notes' => "Takes over assignment #{$departing->id} (".($plan['type'] === AssignmentSuccession::TYPE_TEMPORARY ? 'Temporary employee who covered the position' : 'HR Waiting List').').',
                'correction_reason' => $correctionReason,
            ], $actor);

            $row = AssignmentSuccession::query()->create([
                'departing_assignment_id' => $departing->id,
                'successor_type' => $plan['type'],
                'successor_employee_id' => $employee->id,
                'temporary_replacement_id' => $plan['cover']?->id,
                'successor_assignment_id' => $new->id,
                'departing_last_day' => $plan['last_day']->toDateString(),
                'successor_start_date' => $plan['start']->toDateString(),
                'employment_type_from' => $typeFrom,
                'employment_type_to' => $typeTo,
                'salary_amount' => $plan['salary']['amount'],
                'salary_currency' => $plan['salary']['currency'],
                'salary_frequency' => $plan['salary']['frequency'],
                'salary_source' => $plan['salary']['source'],
                'trigger' => $trigger,
                'chosen_by' => $actor->id,
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Replacement chosen for assignment #{$departing->id}: '{$employee->full_name}' ({$employee->employee_number}) takes over from {$plan['start']->toDateString()}.",
                entityType: EmployeeWorkAssignment::class,
                entityId: $departing->id,
                metadata: [
                    'event' => 'successor_chosen',
                    'trigger' => $trigger,
                    'successor_type' => $plan['type'],
                    'successor_employee_id' => $employee->id,
                    'departing_assignment_id' => $departing->id,
                    'successor_assignment_id' => $new->id,
                    'departing_last_day' => $plan['last_day']->toDateString(),
                    'effective_date' => $plan['start']->toDateString(),
                    'effective_date_entered' => $plan['effective_entered'] ?? false,
                    'employment_type_changed_at_save' => $typeFrom !== $typeTo,
                    'employment_type' => ['from' => $typeFrom, 'to' => $typeTo],
                    'previous_salary' => null,
                    'new_salary' => ['amount' => $plan['salary']['amount'], 'currency' => $plan['salary']['currency'], 'frequency' => $plan['salary']['frequency'], 'source' => $plan['salary']['source']],
                    'temporary_replacement_id' => $plan['cover']?->id,
                    'temporary_replacements_ended' => $endedCovers,
                ],
            ));

            if ($typeFrom !== $typeTo) {
                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Employee '{$employee->full_name}' ({$employee->employee_number}) became Permanent; effective {$plan['start']->toDateString()}.",
                    entityType: Employee::class,
                    entityId: $employee->id,
                    metadata: [
                        'event' => 'temporary_became_permanent',
                        'employment_type' => ['from' => $typeFrom, 'to' => $typeTo],
                        'effective_date' => $plan['start']->toDateString(),
                        'departing_assignment_id' => $departing->id,
                        'successor_assignment_id' => $new->id,
                        'temporary_replacements_ended' => $endedCovers,
                        'previous_salary' => null,
                        'new_salary' => ['amount' => $plan['salary']['amount'], 'currency' => $plan['salary']['currency'], 'frequency' => $plan['salary']['frequency']],
                    ],
                ));
            }

            return $row;
        });
    }

    /** @param  array<string, mixed>|null  $errors */
    private static function fail(string $message, string $code, ?array $errors = null): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422, $errors));
    }
}
