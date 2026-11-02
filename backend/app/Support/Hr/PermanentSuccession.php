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
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\AssignmentSuccession;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
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
 *   person        ANY other person in the HR system (owner decision 2026-11-02: the Waiting List is no longer required; 'waiting_list' is still accepted
 *                 and recorded when the person really was Waiting). The status change to Active goes through UpdateEmployeeStatusAction first. When
 *                 that normal path refuses (the person's status is not one step from Active, or a new registration has not completed the
 *                 registration workflow) the appointment is NOT blocked: it is made as a RECORDED EXCEPTION (status history source
 *                 successor_appointment_exception with the reason and what was missing, an audit event, and the actor), which the HR compliance
 *                 report then shows for follow-up. Protections that remain: an unknown person, the departing employee themself, a person separated as NOT
 *                 eligible for rehire, and every check of CreateWorkAssignmentAction (overlapping shifts, capacity, company rules). The new Permanent
 *                 assignment goes through CreateWorkAssignmentAction and the salary is the one HR enters (never assumed to be inherited).
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

        // A Replacement of a FINISHED cycle (its holder came back) is history, not the next holder: it does not resolve the position. One that is
        // still working, or that covers a holder who was ended while away (the Replacement keeps the position), does.
        $finishedCycleCovers = AssignmentCycleEvent::query()
            ->select('replacement_assignment_id')
            ->where('event_type', AssignmentCycleEvent::REPLACEMENT_STARTED)
            ->whereNotNull('replacement_assignment_id')
            ->whereIn('absence_id', AssignmentAbsence::query()->select('id')->whereNotNull('returned_on'));

        return ! EmployeeWorkAssignment::query()
            ->where('replaces_assignment_id', $assignment->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->whereNotIn('id', $finishedCycleCovers)
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
                'Choose who takes over this Permanent position before it is removed: a Temporary employee who has been covering it, or any other person in the HR system.',
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
            self::fail('The replacement must be a Temporary employee who covered this position or another person in the HR system.', 'SUCCESSOR_TYPE_INVALID');
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
                self::fail("{$employee->full_name} ({$employee->employee_number}) has not been covering this position. Choose a Temporary employee who has, or choose them as another person in the HR system.", 'SUCCESSOR_NOT_COVERING');
            }

            $salary = self::departingSalary($assignment, $lastDay);

            if ($salary === null) {
                self::fail('The departing assignment has no salary to inherit.', 'SUCCESSOR_NO_SALARY_TO_INHERIT');
            }
        } else {
            // Any person may be chosen: the Waiting List is not required, and neither is a particular status or category. The one protection kept here is
            // an existing recorded rule: a person whose latest separation says NOT eligible for rehire is not brought back through this workflow.
            if ($employee->status === EmployeeStatus::Inactive && EmployeeSeparation::query()->where('employee_id', $employee->id)->latest('id')->value('rehire_eligible') === false) {
                self::fail("{$employee->full_name} ({$employee->employee_number}) was separated and recorded as not eligible for rehire, so the person cannot be appointed here.", 'SUCCESSOR_NOT_REHIRE_ELIGIBLE');
            }

            $amount = $choice['salary_amount'] ?? null;
            $currency = strtoupper((string) ($choice['salary_currency'] ?? ''));

            if (! is_numeric($amount) || (float) $amount <= 0 || strlen($currency) !== 3) {
                self::fail('The person chosen needs the salary HR agrees for the position (amount and currency).', 'SUCCESSOR_SALARY_REQUIRED');
            }

            $frequency = (string) ($choice['salary_frequency'] ?? SalaryFrequency::Monthly->value);

            if (! in_array($frequency, SalaryFrequency::values(), true)) {
                self::fail('The salary frequency is not valid.', 'SUCCESSOR_SALARY_FREQUENCY_INVALID');
            }

            $salary = ['amount' => number_format((float) $amount, 2, '.', ''), 'currency' => $currency, 'frequency' => $frequency, 'source' => 'entered'];
        }

        // The record says what the person actually was: Waiting List, or any other person (which includes a requested 'waiting_list' for someone who is not Waiting).
        if ($type !== AssignmentSuccession::TYPE_TEMPORARY) {
            $type = $employee->status === EmployeeStatus::Waiting ? AssignmentSuccession::TYPE_WAITING_LIST : AssignmentSuccession::TYPE_PERSON;
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
            $appointmentException = null;

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
                $appointmentException = self::appoint($employee, $departing, $plan, $actor);
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
                'notes' => "Takes over assignment #{$departing->id} (".match ($plan['type']) {
                    AssignmentSuccession::TYPE_TEMPORARY => 'Temporary employee who covered the position',
                    AssignmentSuccession::TYPE_WAITING_LIST => 'HR Waiting List',
                    default => 'person chosen by HR',
                }.').',
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
                    'appointment_exception' => $appointmentException,
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

    /**
     * Makes the chosen person Active. The normal status workflow is tried first (lifecycle guard, registration workflow, history, audit). When it refuses,
     * HR's choice is still honoured as a RECORDED EXCEPTION - never silently, never blocked: the status history row (source
     * successor_appointment_exception) carries the reason, what the person was and what was still missing, the audit log carries an event, and the HR
     * compliance report lists the person until the missing steps are completed. Returns that record, or null when the normal path sufficed.
     *
     * @param  array{type: string, start: CarbonImmutable}  $plan
     * @return array{from_status: string, missing: list<string>, reason: string}|null
     */
    private static function appoint(Employee $employee, EmployeeWorkAssignment $departing, array $plan, Admin $actor): ?array
    {
        if ($employee->status === EmployeeStatus::Active) {
            return null;
        }

        $note = "Appointed to take over assignment #{$departing->id} from {$plan['start']->toDateString()}.";

        try {
            app(UpdateEmployeeStatusAction::class)->handle($employee, EmployeeStatus::Active, $note, $actor);

            return null;
        } catch (HttpResponseException) {
            // The normal path refused (not one step from Active, or the registration workflow is incomplete). The appointment goes on, recorded below.
        }

        $workflow = new EmployeeRegistrationWorkflow;
        $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
        $from = $locked->status;
        $missing = $workflow->appliesTo($locked) ? $workflow->missing($locked) : [];
        $reason = "Appointed by HR as the successor of assignment #{$departing->id} (effective {$plan['start']->toDateString()}) while the person was '{$from->value}'"
            .($missing === [] ? '' : ' and these registration steps were not complete: '.implode(', ', $missing)).'. Recorded for follow-up in the HR compliance report.';
        $stage = $locked->pipeline_stage;

        $locked->update([
            'status' => EmployeeStatus::Active,
            'pipeline_stage' => null,
            'waiting_since' => $from === EmployeeStatus::Waiting ? null : $locked->waiting_since,
        ]);

        EmployeeStatusHistory::query()->create([
            'employee_id' => $locked->id,
            'from_status' => $from,
            'to_status' => EmployeeStatus::Active,
            'changed_by' => $actor->id,
            'note' => trim($note.($stage === null ? '' : " Pipeline stage '{$stage->label()}' cleared because the person is now Active (the stage is kept here as history).")),
            'override_reason' => $reason,
            'source' => EmployeeRegistrationWorkflow::SOURCE_SUCCESSOR_EXCEPTION,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Employee '{$locked->full_name}' ({$locked->employee_number}) appointed as successor while '{$from->value}': recorded for HR compliance follow-up.",
            entityType: Employee::class,
            entityId: $locked->id,
            metadata: [
                'event' => 'successor_appointment_exception',
                'from_status' => $from->value,
                'to_status' => EmployeeStatus::Active->value,
                'registration_steps_missing' => $missing,
                'departing_assignment_id' => $departing->id,
                'effective_date' => $plan['start']->toDateString(),
                'reason' => $reason,
            ],
        ));

        return ['from_status' => $from->value, 'missing' => $missing, 'reason' => $reason];
    }

    /** @param  array<string, mixed>|null  $errors */
    private static function fail(string $message, string $code, ?array $errors = null): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422, $errors));
    }
}
