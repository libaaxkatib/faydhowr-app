<?php

namespace App\Support\Hr\Recovery;

use App\Enums\AdminRole;
use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\AssignmentSalaryHistory;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\RecoveryCorrection;
use App\Support\ApiResponse;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\CoverConflicts;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use App\Support\Hr\Payroll\PayrollRunCalculator;
use App\Support\Hr\Payroll\PayrollSections;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Super Admin Recovery Center, HR first: safe, previewed, audited corrections of assignment history that no existing screen can make.
 *
 * Four mutating workflows, each limited to what the existing business rules can prove safe:
 *  - reopen_assignment            an ended Permanent assignment continues (end date removed), under the same id;
 *  - restore_cancelled_assignment a Permanent assignment cancelled by mistake becomes active again;
 *  - correct_assignment_dates     start and/or end date moved WITHIN one calendar month (old and new value both inside it);
 *  - correct_absence              the last working day and/or the return date of a Replacement cycle moved within one calendar month.
 * and one read-only workflow, draft_payroll_preview (what the month's payroll would be now, against the stored draft).
 *
 * Protocol: plan() reads only; preview() applies the plan in a transaction that is ALWAYS rolled back, verifies it and recalculates payroll with the
 * production calculator for every affected month (so the numbers shown are the real ones); apply() needs the preview's token, a written reason, an explicit
 * confirmation and an idempotency key, locks the rows, plans again, and applies only if nothing the preview showed has changed - otherwise 409.
 * It never touches payroll: no run, line, payment or deduction is written, so approved, finalized or paid payroll is blocked (guidance only: the existing
 * Super Admin closed-payroll correction is the separate process) and a draft is refreshed by the owner pressing Generate.
 */
final class AssignmentRecovery
{
    public const REOPEN = 'reopen_assignment';

    public const RESTORE = 'restore_cancelled_assignment';

    public const CORRECT_DATES = 'correct_assignment_dates';

    public const CORRECT_ABSENCE = 'correct_absence';

    public const DRAFT_PREVIEW = 'draft_payroll_preview';

    public const MUTATING = [self::REOPEN, self::RESTORE, self::CORRECT_DATES, self::CORRECT_ABSENCE];

    private const MAX_PERIODS = 12;

    private const TOKEN_MINUTES = 15;

    public function __construct(private readonly PayrollRunCalculator $calculator = new PayrollRunCalculator) {}

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return [
            ['id' => self::REOPEN, 'mutating' => true, 'needs_month' => false, 'title' => 'Reopen an ended assignment', 'summary' => 'A Permanent assignment ended by mistake continues again, under the same id. Blocked when a successor, an overlapping assignment, an absence record or closed/paid payroll is involved.'],
            ['id' => self::RESTORE, 'mutating' => true, 'needs_month' => false, 'title' => 'Restore a cancelled assignment', 'summary' => 'A Permanent assignment cancelled by mistake becomes active again with its original dates.'],
            ['id' => self::CORRECT_DATES, 'mutating' => true, 'needs_month' => true, 'title' => 'Correct the start or end date within one month', 'summary' => 'Moves the start and/or the existing end date of an assignment. The old and the new date must be in the selected month and no other month may change.'],
            ['id' => self::CORRECT_ABSENCE, 'mutating' => true, 'needs_month' => true, 'title' => 'Correct a Replacement cycle (last working day / return date)', 'summary' => 'Moves the last working day and/or the return date of a recorded absence within one month; the covering Replacement follows the return date.'],
            ['id' => self::DRAFT_PREVIEW, 'mutating' => false, 'needs_month' => true, 'title' => 'Draft payroll recalculation preview', 'summary' => 'Shows what the month\'s payroll would be now compared with the stored draft. Nothing is saved; press Generate on the Payroll page to refresh the draft.'],
        ];
    }

    // ------------------------------------------------------------------ reading

    /**
     * Everything the Recovery Center shows about one assignment, and which workflows apply to it.
     *
     * @return array<string, mixed>
     */
    public function inspect(EmployeeWorkAssignment $a): array
    {
        $a->loadMissing(['employee', 'clientCompany', 'position', 'workLocation']);
        $absences = AssignmentAbsence::query()->where('work_assignment_id', $a->id)->orderBy('absent_from')->get();
        $events = AssignmentCycleEvent::query()->where('work_assignment_id', $a->id)->orderBy('id')->get();
        $lines = PayrollItem::query()->where('work_assignment_id', $a->id)->orderBy('period')->get();
        $runs = PayrollRun::query()->whereIn('period', $lines->pluck('period')->unique()->all())->get()->mapWithKeys(fn (PayrollRun $r): array => [$r->period => PayrollSections::lockStatus($r)]);

        $workflows = [];

        foreach (self::MUTATING as $id) {
            $available = match ($id) {
                self::REOPEN => $a->status !== WorkAssignmentStatus::Cancelled && $a->end_date !== null,
                self::RESTORE => $a->status === WorkAssignmentStatus::Cancelled,
                self::CORRECT_DATES => $a->status !== WorkAssignmentStatus::Cancelled,
                self::CORRECT_ABSENCE => $absences->isNotEmpty(),
            };
            $entry = ['id' => $id, 'available' => $available, 'blockers' => []];

            if ($available && in_array($id, [self::REOPEN, self::RESTORE], true)) {
                $entry['blockers'] = $this->plan($id, $a, [])['blockers'];
            }

            $workflows[] = $entry;
        }

        return [
            'assignment' => [
                'id' => $a->id, 'type' => ($a->assignment_type ?? AssignmentType::Permanent)->value, 'status' => $a->status->value,
                'start_date' => $a->start_date->toDateString(), 'end_date' => $a->end_date?->toDateString(), 'replaces_assignment_id' => $a->replaces_assignment_id,
                'salary_amount' => (string) $a->salary_amount, 'salary_currency' => $a->salary_currency, 'position' => $a->position?->name, 'shift' => $a->shift?->value,
                'company' => $a->clientCompany?->name, 'company_id' => $a->client_company_id, 'workplace' => $a->workLocation?->name,
                'employee' => ['id' => $a->employee?->id, 'number' => $a->employee?->employee_number, 'name' => $a->employee?->full_name, 'status' => $a->employee?->status?->value, 'type' => $a->employee?->employment_type],
            ],
            'successors' => EmployeeWorkAssignment::query()->where('replaces_assignment_id', $a->id)->orderBy('start_date')->get(['id', 'assignment_type', 'status', 'start_date', 'end_date', 'employee_id'])
                ->map(fn (EmployeeWorkAssignment $s): array => ['id' => $s->id, 'type' => $s->assignment_type?->value, 'status' => $s->status->value, 'start_date' => $s->start_date->toDateString(), 'end_date' => $s->end_date?->toDateString()])->all(),
            'absences' => $absences->map(fn (AssignmentAbsence $x): array => ['id' => $x->id, 'last_working_day' => $x->last_working_day->toDateString(), 'absent_from' => $x->absent_from->toDateString(), 'returned_on' => $x->returned_on?->toDateString(), 'terminated_on' => $x->terminated_on?->toDateString()])->all(),
            'cycle_events' => $events->map(fn (AssignmentCycleEvent $e): array => ['absence_id' => $e->absence_id, 'event_type' => $e->event_type, 'event_date' => $e->event_date->toDateString(), 'replacement_assignment_id' => $e->replacement_assignment_id])->all(),
            'salary_history' => AssignmentSalaryHistory::query()->where('work_assignment_id', $a->id)->orderBy('effective_from')->get()->map(fn (AssignmentSalaryHistory $h): array => ['id' => $h->id, 'effective_from' => $h->effective_from->toDateString(), 'amount' => (string) $h->amount, 'currency' => $h->currency])->all(),
            'payroll_lines' => $lines->map(fn (PayrollItem $i): array => ['period' => $i->period, 'run_status' => $runs[$i->period] ?? null, 'days' => $i->eligible_days, 'gross_cents' => (int) $i->gross_cents, 'net_cents' => (int) $i->net_cents, 'payment_status' => $i->payment_status])->all(),
            'workflows' => $workflows,
        ];
    }

    /**
     * Draft payroll recalculation preview (read-only): the stored lines of a month's run against what the production calculator gives now.
     *
     * @param  list<int>  $employeeIds  empty = the whole month
     * @return array<string, mixed>
     */
    public function draftPreview(string $month, array $employeeIds = []): array
    {
        $this->assertMonth($month);
        $run = PayrollRun::query()->where('period', $month)->first();
        $stored = [];

        if ($run !== null) {
            foreach (PayrollItem::query()->where('payroll_run_id', $run->id)->whereNull('voided_at')->when($employeeIds !== [], fn ($q) => $q->whereIn('employee_id', $employeeIds))->get() as $i) {
                $stored[$i->work_assignment_id] = $this->line($i->employee_id, $i->employee_number, $i->employee_name, $i->eligible_days, (int) $i->gross_cents, (int) $i->net_cents);
            }
        }

        $live = $this->lines([$month], $employeeIds)[$month];

        return [
            'month' => $month, 'run_status' => $run?->status, 'generated_at' => $run?->generated_at?->toIso8601String(),
            'locked' => $run !== null && in_array(PayrollSections::lockStatus($run), PayrollPeriodLock::LOCKED_STATUSES, true),
            'comparison' => $this->compare($stored, $live),
            'guidance' => $run === null ? 'There is no payroll run for this month yet: press Generate on the Payroll page to create the draft.'
                : (in_array(PayrollSections::lockStatus($run), PayrollPeriodLock::LOCKED_STATUSES, true) ? 'This payroll is approved or finalized. It is never rewritten: any difference is handled by the existing Super Admin payroll correction.' : 'Nothing was saved. To bring the stored draft up to date press Generate on the Payroll page; it is never approved, finalized or paid automatically.'),
        ];
    }

    // ------------------------------------------------------------------ planning (reads only)

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function plan(string $workflow, EmployeeWorkAssignment $a, array $params): array
    {
        $a->loadMissing(['employee', 'clientCompany']);
        $plan = match ($workflow) {
            self::REOPEN => $this->planReopen($a),
            self::RESTORE => $this->planRestore($a),
            self::CORRECT_DATES => $this->planDates($a, $params),
            self::CORRECT_ABSENCE => $this->planAbsence($a, $params),
            default => throw new \InvalidArgumentException("Unknown workflow {$workflow}."),
        };

        $plan = ['workflow' => $workflow, 'assignment_id' => $a->id, 'params' => $this->normalizedParams($workflow, $params), ...$plan];
        $plan['employee_ids'] = array_values(array_unique(array_filter([$a->employee_id, ...array_map(fn (array $c): ?int => $c['employee_id'] ?? null, $plan['changes'])])));
        $plan['plan_hash'] = hash('sha256', json_encode([$workflow, $a->id, $plan['params'], $plan['changes'], $plan['blockers']], JSON_THROW_ON_ERROR));

        return $plan;
    }

    /** @return array<string, mixed> */
    private function planReopen(EmployeeWorkAssignment $a): array
    {
        $blockers = [];
        $end = $a->end_date?->toDateString();

        if (($a->assignment_type ?? AssignmentType::Permanent) === AssignmentType::Replacement) {
            $blockers[] = $this->b('REPLACEMENT_ROW', 'This is a Replacement. A Replacement is ended and returned through Return Original Employee / Replace Employee, which keep the cycle consistent.', '/hr/companies/'.$a->client_company_id.'/employees');
        }

        if ($a->status === WorkAssignmentStatus::Cancelled) {
            $blockers[] = $this->b('IS_CANCELLED', 'This assignment is cancelled, not ended. Use "Restore a cancelled assignment" instead.');
        } elseif ($end === null) {
            $blockers[] = $this->b('ALREADY_OPEN', 'This assignment has no end date, so there is nothing to reopen.');
        }

        $blockers = [...$blockers, ...$this->employeeBlockers($a), ...$this->absenceBlockers($a), ...$this->successorBlockers($a)];

        if ($end !== null) {
            $blockers = [...$blockers, ...$this->overlapBlockers($a, CarbonImmutable::parse($end)->addDay()->toDateString(), null)];
        }

        $month = $end === null ? null : substr($end, 0, 7);
        $periods = $month === null ? [] : $this->periodsFrom($month, $blockers);
        $blockers = [...$blockers, ...$this->payrollBlockers($periods, [$a->employee_id])];

        return [
            'month' => $month, 'periods' => $periods, 'blockers' => $this->unique($blockers), 'warnings' => $periods === [] ? [] : ['Reopening makes the assignment open-ended again: every month from '.$month.' onward is paid to this employee, as the preview shows.'],
            'changes' => [['entity' => 'assignment', 'id' => $a->id, 'employee_id' => $a->employee_id, 'label' => "Assignment #{$a->id} ({$a->employee?->full_name})", 'before' => ['status' => $a->status->value, 'end_date' => $end], 'after' => ['status' => 'active', 'end_date' => null]]],
        ];
    }

    /** @return array<string, mixed> */
    private function planRestore(EmployeeWorkAssignment $a): array
    {
        $blockers = [];
        $start = $a->start_date->toDateString();
        $end = $a->end_date?->toDateString();

        if ($a->status !== WorkAssignmentStatus::Cancelled) {
            $blockers[] = $this->b('NOT_CANCELLED', 'This assignment is not cancelled.');
        }

        if (($a->assignment_type ?? AssignmentType::Permanent) === AssignmentType::Replacement) {
            $blockers[] = $this->b('REPLACEMENT_ROW', 'A cancelled Replacement is not restored here: enter the cover again with Replace Employee so the absence and its ledger stay consistent.', '/hr/companies/'.$a->client_company_id.'/employees');
        }

        $blockers = [...$blockers, ...$this->employeeBlockers($a), ...$this->absenceBlockers($a), ...$this->overlapBlockers($a, $start, $end)];

        if ($a->replaces_assignment_id !== null) {
            $predecessor = EmployeeWorkAssignment::query()->find($a->replaces_assignment_id);

            if ($predecessor === null) {
                $blockers[] = $this->b('PREDECESSOR_MISSING', 'The assignment this one took over from no longer exists.');
            } else {
                try {
                    PositionSuccession::assertCanFollow($predecessor, $a->employee_id, CarbonImmutable::parse($start), $a->id);
                } catch (HttpResponseException $e) {
                    $blockers[] = $this->b('SUCCESSION_CONFLICT', $this->messageOf($e));
                }
            }
        }

        $periods = $this->periodsFrom(substr($start, 0, 7), $blockers);
        $blockers = [...$blockers, ...$this->payrollBlockers($periods, [$a->employee_id])];

        return [
            'month' => substr($start, 0, 7), 'periods' => $periods, 'blockers' => $this->unique($blockers), 'warnings' => [],
            'changes' => [['entity' => 'assignment', 'id' => $a->id, 'employee_id' => $a->employee_id, 'label' => "Assignment #{$a->id} ({$a->employee?->full_name})", 'before' => ['status' => 'cancelled'], 'after' => ['status' => 'active']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function planDates(EmployeeWorkAssignment $a, array $p): array
    {
        $blockers = [];
        $month = (string) ($p['month'] ?? '');
        $oldStart = $a->start_date->toDateString();
        $oldEnd = $a->end_date?->toDateString();
        $newStart = isset($p['start_date']) && $p['start_date'] !== '' ? CarbonImmutable::parse($p['start_date'])->toDateString() : null;
        $newEnd = isset($p['end_date']) && $p['end_date'] !== '' ? CarbonImmutable::parse($p['end_date'])->toDateString() : null;
        $startChanges = $newStart !== null && $newStart !== $oldStart;
        $endChanges = $newEnd !== null && $newEnd !== $oldEnd;
        $changes = [];
        $before = [];
        $after = [];

        if (! $this->validMonth($month)) {
            return ['month' => null, 'periods' => [], 'warnings' => [], 'changes' => [], 'blockers' => [$this->b('INVALID_MONTH', 'Choose the month (YYYY-MM) the correction is limited to.')]];
        }

        if (! $startChanges && ! $endChanges) {
            $blockers[] = $this->b('NOTHING_TO_CHANGE', 'Enter a different start date and/or end date.');
        }

        if ($a->status === WorkAssignmentStatus::Cancelled) {
            $blockers[] = $this->b('IS_CANCELLED', 'This assignment is cancelled. Restore it first.');
        }

        if (AssignmentAbsence::query()->where('work_assignment_id', $a->id)->exists() || ($this->isBoundCover($a))) {
            $blockers[] = $this->b('USES_REPLACEMENT_CYCLE', 'This assignment is part of a Replacement cycle (an absence is recorded). Use "Correct a Replacement cycle" so the absence, the Replacement and the ledger stay consistent.');
        }

        if ($startChanges) {
            foreach (['old' => $oldStart, 'new' => $newStart] as $which => $date) {
                if (! str_starts_with($date, $month.'-')) {
                    $blockers[] = $this->b('DATE_OUTSIDE_MONTH', "The {$which} start date ({$date}) is outside {$month}. A correction that reaches another month is blocked, never widened.");
                }
            }

            $before['start_date'] = $oldStart;
            $after['start_date'] = $newStart;
        }

        if ($endChanges) {
            if ($oldEnd === null) {
                $blockers[] = $this->b('USE_STOP', 'This assignment is open. Setting its first end date is Stop / End assignment (which asks for the replacement choice), not a date correction.', '/hr/companies/'.$a->client_company_id.'/employees');
            } else {
                foreach (['old' => $oldEnd, 'new' => $newEnd] as $which => $date) {
                    if (! str_starts_with($date, $month.'-')) {
                        $blockers[] = $this->b('DATE_OUTSIDE_MONTH', "The {$which} end date ({$date}) is outside {$month}. A correction that reaches another month is blocked, never widened.");
                    }
                }

                $before['end_date'] = $oldEnd;
                $after['end_date'] = $newEnd;
            }
        }

        $finalStart = $newStart ?? $oldStart;
        $finalEnd = $newEnd ?? $oldEnd;

        if ($finalEnd !== null && $finalEnd < $finalStart) {
            $blockers[] = $this->b('END_BEFORE_START', "The end date ({$finalEnd}) would be before the start date ({$finalStart}).");
        }

        if ($endChanges && $oldEnd !== null) {
            try {
                PositionSuccession::assertEndKeepsSuccessorClear($a, CarbonImmutable::parse($newEnd));
            } catch (HttpResponseException $e) {
                $blockers[] = $this->b('SUCCESSION_CONFLICT', $this->messageOf($e));
            }
        }

        if ($startChanges && $a->replaces_assignment_id !== null) {
            $predecessor = EmployeeWorkAssignment::query()->find($a->replaces_assignment_id);

            if ($predecessor !== null) {
                try {
                    PositionSuccession::assertCanFollow($predecessor, $a->employee_id, CarbonImmutable::parse($newStart), $a->id);
                } catch (HttpResponseException $e) {
                    $blockers[] = $this->b('SUCCESSION_CONFLICT', $this->messageOf($e));
                }
            }
        }

        // Owner decision O-1 (symmetrical): a Replacement's corrected dates may not share a day with a Temporary cover of the position it holds.
        if (($startChanges || $endChanges) && ($finalEnd === null || $finalEnd >= $finalStart)) {
            $coverInTheWay = CoverConflicts::findCoverOverlappingReplacement($a, $finalStart, $finalEnd, 'changed');

            if ($coverInTheWay !== null) {
                $blockers[] = $this->b('COVER_OVERLAPS_REPLACEMENT', $coverInTheWay['message']);
            }
        }

        // Owner rule A7: a Replacement's corrected dates may not share a day with another Replacement of the same position (a day is paid once).
        if (($startChanges || $endChanges) && ($finalEnd === null || $finalEnd >= $finalStart) && ($a->assignment_type ?? AssignmentType::Permanent) === AssignmentType::Replacement) {
            $siblingOverlap = PositionSuccession::replacementOverlapConflict($a, $finalStart, $finalEnd, 'changed');

            if ($siblingOverlap !== null) {
                $blockers[] = $this->b(PositionSuccession::CODE_REPLACEMENT_OVERLAPS_REPLACEMENT, $siblingOverlap['message']);
            }
        }

        // Owner decision F-A: a Replacement of a holder who has returned must end the day before the return at the latest.
        if (($startChanges || $endChanges) && ($finalEnd === null || $finalEnd >= $finalStart)) {
            $pastReturn = AbsenceCycles::replacementReturnConflict($a, $finalEnd);

            if ($pastReturn !== null) {
                $blockers[] = $this->b(AbsenceCycles::CODE_REPLACEMENT_PAST_RETURN, $pastReturn['message']);
            }
        }

        $changes[] = ['entity' => 'assignment', 'id' => $a->id, 'employee_id' => $a->employee_id, 'label' => "Assignment #{$a->id} ({$a->employee?->full_name})", 'before' => $before, 'after' => $after];

        if ($startChanges) {
            $histories = AssignmentSalaryHistory::query()->where('work_assignment_id', $a->id)->orderBy('effective_from')->get();
            $first = $histories->first();

            if ($first !== null && $first->effective_from->toDateString() === $oldStart) {
                if ($histories->skip(1)->contains(fn ($h): bool => $h->effective_from->toDateString() <= $newStart)) {
                    $blockers[] = $this->b('START_PASSES_SALARY_CHANGE', 'The new start date is on or after a later salary change. Correct the salary history first.');
                } else {
                    $changes[] = ['entity' => 'salary_history', 'id' => $first->id, 'employee_id' => $a->employee_id, 'label' => 'First salary row of assignment #'.$a->id, 'before' => ['effective_from' => $oldStart], 'after' => ['effective_from' => $newStart]];
                }
            }
        }

        $blockers = [...$blockers, ...$this->overlapBlockers($a, $finalStart, $finalEnd), ...$this->payrollBlockers([$month], [$a->employee_id])];

        return ['month' => $month, 'periods' => [$month], 'blockers' => $this->unique($blockers), 'warnings' => [], 'changes' => $changes];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function planAbsence(EmployeeWorkAssignment $a, array $p): array
    {
        $month = (string) ($p['month'] ?? '');

        if (! $this->validMonth($month)) {
            return ['month' => null, 'periods' => [], 'warnings' => [], 'changes' => [], 'blockers' => [$this->b('INVALID_MONTH', 'Choose the month (YYYY-MM) the correction is limited to.')]];
        }

        $absence = AssignmentAbsence::query()->where('work_assignment_id', $a->id)->find((int) ($p['absence_id'] ?? 0));

        if ($absence === null) {
            return ['month' => $month, 'periods' => [$month], 'warnings' => [], 'changes' => [], 'blockers' => [$this->b('ABSENCE_NOT_FOUND', 'That absence does not belong to this assignment.')]];
        }

        $blockers = [];
        $oldLast = $absence->last_working_day->toDateString();
        $oldFrom = $absence->absent_from->toDateString();
        $oldReturn = $absence->returned_on?->toDateString();
        $newLast = isset($p['last_working_day']) && $p['last_working_day'] !== '' ? CarbonImmutable::parse($p['last_working_day'])->toDateString() : $oldLast;
        $newReturn = isset($p['returned_on']) && $p['returned_on'] !== '' ? CarbonImmutable::parse($p['returned_on'])->toDateString() : $oldReturn;
        $lastChanges = $newLast !== $oldLast;
        $returnChanges = $newReturn !== $oldReturn;
        $newFrom = CarbonImmutable::parse($newLast)->addDay()->toDateString();
        $before = [];
        $after = [];
        $changes = [];

        if (! $lastChanges && ! $returnChanges) {
            $blockers[] = $this->b('NOTHING_TO_CHANGE', 'Enter a different last working day and/or return date.');
        }

        if ($absence->terminated_on !== null) {
            $blockers[] = $this->b('ABSENCE_TERMINATED', 'The assignment was ended while its holder was away; there is no return to correct. Use Stop Employee / Replace Employee.', '/hr/companies/'.$a->client_company_id.'/employees');
        }

        if ($oldReturn !== null && $oldReturn === $oldFrom) {
            $blockers[] = $this->b('ABSENCE_CANCELLED', 'This absence was closed as a cancelled cover (zero days); there is nothing to correct.');
        }

        if ($lastChanges) {
            foreach (['old' => $oldLast, 'new' => $newLast] as $which => $date) {
                if (! str_starts_with($date, $month.'-')) {
                    $blockers[] = $this->b('DATE_OUTSIDE_MONTH', "The {$which} last working day ({$date}) is outside {$month}. A correction that reaches another month is blocked, never widened.");
                }
            }

            if ($newLast < $a->start_date->toDateString()) {
                $blockers[] = $this->b('BEFORE_ASSIGNMENT_START', "The last working day ({$newLast}) is before the assignment started ({$a->start_date->toDateString()}).");
            }

            $before['last_working_day'] = $oldLast;
            $before['absent_from'] = $oldFrom;
            $after['last_working_day'] = $newLast;
            $after['absent_from'] = $newFrom;
        }

        if ($returnChanges) {
            if ($oldReturn === null) {
                $blockers[] = $this->b('USE_RETURN', 'The holder has not returned yet. Recording the return is Return Original Employee, not a correction.', '/hr/companies/'.$a->client_company_id.'/employees');
            } elseif ($newReturn === null) {
                $blockers[] = $this->b('UNDO_RETURN_NOT_SUPPORTED', 'Removing a recorded return is not supported here (it would reopen the cover). Correct the return date instead.');
            } else {
                foreach (['old' => $oldReturn, 'new' => $newReturn] as $which => $date) {
                    if (! str_starts_with($date, $month.'-')) {
                        $blockers[] = $this->b('DATE_OUTSIDE_MONTH', "The {$which} return date ({$date}) is outside {$month}. A correction that reaches another month is blocked, never widened.");
                    }
                }

                $before['returned_on'] = $oldReturn;
                $after['returned_on'] = $newReturn;
            }
        }

        if ($newReturn !== null && $newReturn <= $newFrom) {
            $blockers[] = $this->b('RETURN_NOT_AFTER_ABSENCE', "The return date ({$newReturn}) must be after the first day of the absence ({$newFrom}).");
        }

        foreach (AssignmentAbsence::query()->where('work_assignment_id', $a->id)->where('id', '!=', $absence->id)->get() as $other) {
            $oFrom = $other->absent_from->toDateString();
            $oTo = $other->returned_on?->copy()->subDay()->toDateString();
            $mine = $newReturn === null ? null : CarbonImmutable::parse($newReturn)->subDay()->toDateString();

            if ($newFrom <= ($oTo ?? '9999-12-31') && $oFrom <= ($mine ?? '9999-12-31')) {
                $blockers[] = $this->b('ABSENCE_OVERLAP', "The corrected absence would overlap another absence of this assignment ({$oFrom}).");
            }
        }

        $covers = EmployeeWorkAssignment::query()
            ->whereIn('id', AssignmentCycleEvent::query()->where('absence_id', $absence->id)->where('event_type', AssignmentCycleEvent::REPLACEMENT_STARTED)->whereNotNull('replacement_assignment_id')->select('replacement_assignment_id'))
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)->orderBy('start_date')->get();

        foreach ($covers as $cover) {
            if ($lastChanges && $cover->start_date->toDateString() < $newFrom) {
                $blockers[] = $this->b('REPLACEMENT_STARTS_BEFORE_ABSENCE', "Replacement #{$cover->id} started on {$cover->start_date->toDateString()}, before the corrected absence begins ({$newFrom}).");
            }
        }

        $changes[] = ['entity' => 'absence', 'id' => $absence->id, 'employee_id' => $a->employee_id, 'label' => "Absence of assignment #{$a->id}", 'before' => $before, 'after' => $after];

        if ($returnChanges && $oldReturn !== null && $newReturn !== null) {
            $oldCoverEnd = CarbonImmutable::parse($oldReturn)->subDay()->toDateString();
            $newCoverEnd = CarbonImmutable::parse($newReturn)->subDay()->toDateString();
            $ended = $covers->first(fn (EmployeeWorkAssignment $c): bool => $c->end_date?->toDateString() === $oldCoverEnd);

            if ($ended !== null) {
                if ($newCoverEnd < $ended->start_date->toDateString()) {
                    $blockers[] = $this->b('RETURN_BEFORE_REPLACEMENT_STARTED', "The return would end Replacement #{$ended->id} ({$newCoverEnd}) before it started ({$ended->start_date->toDateString()}).");
                }

                $later = $covers->first(fn (EmployeeWorkAssignment $c): bool => $c->id !== $ended->id && $c->start_date->toDateString() <= $newCoverEnd && ($c->end_date === null || $c->end_date->toDateString() >= $ended->start_date->toDateString()) && $c->start_date->toDateString() > $ended->start_date->toDateString());

                if ($later !== null) {
                    $blockers[] = $this->b('REPLACEMENT_OVERLAP', "Replacement #{$later->id} would overlap the Replacement that ends at the return.");
                }

                $changes[] = ['entity' => 'assignment', 'id' => $ended->id, 'employee_id' => $ended->employee_id, 'label' => "Replacement #{$ended->id} (ends the day before the return)", 'before' => ['end_date' => $oldCoverEnd], 'after' => ['end_date' => $newCoverEnd]];
            }
        }

        // Owner decision F-A: after the correction every Replacement of this absence must end the day before the (corrected) return at the latest. The one that
        // ends at the old return follows it (above); any other must already be clear of it.
        if ($returnChanges && $oldReturn !== null && $newReturn !== null) {
            $oldCoverEndDay = CarbonImmutable::parse($oldReturn)->subDay()->toDateString();
            $newCoverEndDay = CarbonImmutable::parse($newReturn)->subDay()->toDateString();
            $followsReturn = $covers->first(fn (EmployeeWorkAssignment $c): bool => $c->end_date?->toDateString() === $oldCoverEndDay)?->id;

            foreach ($covers as $cover) {
                $endsOn = $cover->id === $followsReturn ? $newCoverEndDay : $cover->end_date?->toDateString();

                if ($endsOn === null || $endsOn > $newCoverEndDay) {
                    $blockers[] = $this->b(AbsenceCycles::CODE_REPLACEMENT_PAST_RETURN, "A Replacement must end no later than the day before the original employee returns. After this correction the return is {$newReturn}, but Replacement #{$cover->id} would last ".($endsOn === null ? 'with no end date' : "until {$endsOn}").". The latest allowed last day is {$newCoverEndDay}.");
                }
            }
        }

        $blockers = [...$blockers, ...$this->payrollBlockers([$month], array_values(array_unique([$a->employee_id, ...$covers->pluck('employee_id')->all()])))];

        return ['month' => $month, 'periods' => [$month], 'blockers' => $this->unique($blockers), 'warnings' => [], 'changes' => $changes];
    }

    // ------------------------------------------------------------------ preview (applied, verified and rolled back)

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function preview(array $plan, ?Admin $actor): array
    {
        $blockers = $plan['blockers'];
        $impact = null;
        $verification = ['ok' => false, 'checks' => [], 'problems' => []];

        if ($blockers === []) {
            [$impact, $verification, $extra] = $this->simulate($plan);
            $blockers = [...$blockers, ...$extra];
        }

        $ok = $blockers === [] && $verification['ok'];

        return [
            ...$plan, 'blockers' => $blockers, 'impact' => $impact, 'verification' => $verification,
            'can_apply' => $ok,
            'token' => $ok && $actor !== null ? $this->makeToken($plan, $impact, $actor) : null,
            'token_valid_minutes' => self::TOKEN_MINUTES,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{0: array<string, mixed>, 1: array{ok: bool, checks: array<string, bool>, problems: list<string>}, 2: list<array<string, string>>}
     */
    private function simulate(array $plan): array
    {
        $periods = $this->impactPeriods($plan);
        $employees = $plan['employee_ids'];
        $before = $this->lines($periods, $employees);
        $guard = $this->snapshot($plan);

        DB::beginTransaction();

        try {
            $this->applyChanges($plan, null, false, '', '');
            $verification = $this->verify($plan, $guard);
            $after = $this->lines($periods, $employees);
        } finally {
            DB::rollBack();
        }

        $blockers = [];
        $shown = [];

        foreach ($periods as $period) {
            $cmp = $this->compare($before[$period], $after[$period]);

            if (! in_array($period, $plan['periods'], true) && $cmp['changed_employees'] !== []) {
                $blockers[] = $this->b('CROSS_MONTH_EFFECT', "This correction would also change the pay of {$period}, which is outside the selected month. It is blocked, never widened.");
            }

            if (in_array($period, $plan['periods'], true)) {
                $shown[$period] = $cmp;
            }
        }

        $impact = ['periods' => $shown, 'currency' => 'USD', 'hash' => hash('sha256', json_encode($shown, JSON_THROW_ON_ERROR))];

        return [$impact, $verification, $blockers];
    }

    // ------------------------------------------------------------------ apply (the only writer)

    /**
     * @param  array<string, mixed>  $params
     * @return array{correction: RecoveryCorrection, replayed: bool}
     */
    public function apply(string $workflow, EmployeeWorkAssignment $a, array $params, Admin $actor, string $reason, string $idempotencyKey, string $token): array
    {
        if ($actor->role !== AdminRole::SuperAdmin) {
            throw new HttpResponseException(ApiResponse::error('Recovery corrections are reserved for Super Admin.', 'FORBIDDEN', 403));
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            $this->fail('A correction reason of at least 10 characters is required.', 'REASON_REQUIRED', 422);
        }

        $claims = $this->readToken($token, $actor, $workflow);
        $existing = RecoveryCorrection::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $this->replay($existing, $claims);
        }

        try {
            $correction = DB::transaction(function () use ($workflow, $a, $params, $actor, $reason, $idempotencyKey, $claims): RecoveryCorrection {
                $plan = $this->plan($workflow, $a, $params);
                $ids = array_values(array_unique(array_map(fn (array $c): int => (int) $c['id'], array_filter($plan['changes'], fn (array $c): bool => $c['entity'] === 'assignment'))));
                sort($ids);

                // Lock the month's payroll runs first (nobody can approve one under us), then every assignment we will change, in a fixed order.
                PayrollRun::query()->whereIn('period', $plan['periods'] === [] ? ['0000-00'] : $plan['periods'])->orderBy('id')->lockForUpdate()->get();
                EmployeeWorkAssignment::query()->whereIn('id', [$a->id, ...$ids])->orderBy('id')->lockForUpdate()->get();

                $plan = $this->plan($workflow, EmployeeWorkAssignment::query()->findOrFail($a->id), $params);

                if ($plan['plan_hash'] !== $claims['p']) {
                    $this->fail('The data changed after the preview. Nothing was applied; preview it again.', 'PREVIEW_STALE', 409);
                }

                if ($plan['blockers'] !== []) {
                    $this->fail('This correction is blocked: '.$plan['blockers'][0]['message'], 'CORRECTION_BLOCKED', 422);
                }

                [$impact, , $extra] = $this->simulate($plan);

                if ($extra !== [] || $impact['hash'] !== $claims['i']) {
                    $this->fail('The payroll impact shown in the preview has changed. Nothing was applied; preview it again.', 'PREVIEW_STALE', 409);
                }

                $guard = $this->snapshot($plan);
                $uuid = (string) Str::uuid();
                $this->applyChanges($plan, $actor, true, $reason, $uuid);
                $verification = $this->verify($plan, $guard);

                if (! $verification['ok']) {
                    $this->fail('Verification failed, everything was rolled back: '.implode(' ', $verification['problems']), 'VERIFICATION_FAILED', 422);
                }

                $correction = RecoveryCorrection::query()->create([
                    'uuid' => $uuid, 'idempotency_key' => $idempotencyKey, 'workflow' => $workflow, 'month' => $plan['month'],
                    'subject_type' => EmployeeWorkAssignment::class, 'subject_id' => $a->id, 'actor_id' => $actor->id, 'reason' => $reason,
                    'plan_hash' => $plan['plan_hash'], 'params' => $plan['params'], 'changes' => $plan['changes'], 'impact' => $impact, 'status' => 'applied',
                ]);

                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Recovery correction applied ({$workflow}) to assignment #{$a->id}: {$reason}",
                    entityType: RecoveryCorrection::class,
                    entityId: $correction->id,
                    metadata: ['event' => 'recovery_correction', 'workflow' => $workflow, 'correction' => $uuid, 'assignment_id' => $a->id, 'month' => $plan['month'], 'reason' => $reason, 'changes' => $plan['changes'], 'plan_hash' => $plan['plan_hash']],
                ));

                return $correction;
            });
        } catch (UniqueConstraintViolationException) {
            // A second request with the same key got there first: it is the same correction, never a second one.
            $existing = RecoveryCorrection::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

            return $this->replay($existing, $claims);
        }

        return ['correction' => $correction, 'replayed' => false];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function applyChanges(array $plan, ?Admin $actor, bool $audit, string $reason, string $batch): void
    {
        $note = 'Recovery correction'.($batch !== '' ? ' '.Str::limit($batch, 8, '') : '').' ('.$plan['workflow'].')';

        foreach ($plan['changes'] as $change) {
            if ($change['entity'] === 'assignment') {
                $row = EmployeeWorkAssignment::query()->lockForUpdate()->findOrFail($change['id']);
                $values = $change['after'];

                if (isset($values['status'])) {
                    $values['status'] = WorkAssignmentStatus::from($values['status']);
                }

                $row->update([...$values, 'notes' => trim(($row->notes ? $row->notes."\n" : '').$note.': '.json_encode($change['after']))]);
            } elseif ($change['entity'] === 'salary_history') {
                AssignmentSalaryHistory::query()->lockForUpdate()->findOrFail($change['id'])->update($change['after']);
            } else {
                $absence = AssignmentAbsence::query()->lockForUpdate()->findOrFail($change['id']);
                $absence->update($change['after']);

                if ($audit) {
                    AbsenceCycles::event($absence, AssignmentCycleEvent::WINDOW_CORRECTED, CarbonImmutable::now(), $actor, null, ['before' => $change['before'], 'after' => $change['after'], 'reason' => $reason]);
                }
            }

            if ($audit) {
                $type = match ($change['entity']) {
                    'assignment' => EmployeeWorkAssignment::class, 'salary_history' => AssignmentSalaryHistory::class, default => AssignmentAbsence::class,
                };
                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "{$change['label']} corrected by the Recovery Center.",
                    entityType: $type,
                    entityId: (int) $change['id'],
                    metadata: ['event' => 'recovery_change', 'workflow' => $plan['workflow'], 'correction' => $batch, 'reason' => $reason, 'before' => $change['before'], 'after' => $change['after']],
                ));
            }
        }
    }

    // ------------------------------------------------------------------ verification

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, string>
     */
    private function snapshot(array $plan): array
    {
        $assignmentIds = array_values(array_unique([$plan['assignment_id'], ...array_map(fn (array $c): int => (int) $c['id'], array_filter($plan['changes'], fn (array $c): bool => $c['entity'] === 'assignment'))]));
        $absenceIds = array_map(fn (array $c): int => (int) $c['id'], array_filter($plan['changes'], fn (array $c): bool => $c['entity'] === 'absence'));
        $historyIds = array_map(fn (array $c): int => (int) $c['id'], array_filter($plan['changes'], fn (array $c): bool => $c['entity'] === 'salary_history'));

        return [
            'other_assignments' => hash('sha256', json_encode(EmployeeWorkAssignment::query()->whereNotIn('id', $assignmentIds)->orderBy('id')->get(['id', 'employee_id', 'client_company_id', 'assignment_type', 'replaces_assignment_id', 'start_date', 'end_date', 'status', 'salary_amount', 'position_id', 'shift'])->toArray(), JSON_THROW_ON_ERROR)),
            'other_salary_histories' => hash('sha256', json_encode(AssignmentSalaryHistory::query()->whereNotIn('id', $historyIds === [] ? [0] : $historyIds)->orderBy('id')->get(['id', 'work_assignment_id', 'effective_from', 'amount'])->toArray(), JSON_THROW_ON_ERROR)),
            'other_absences' => hash('sha256', json_encode(AssignmentAbsence::query()->whereNotIn('id', $absenceIds === [] ? [0] : $absenceIds)->orderBy('id')->get()->toArray(), JSON_THROW_ON_ERROR)),
            'employees' => hash('sha256', json_encode(Employee::query()->withTrashed()->orderBy('id')->get(['id', 'status', 'employment_type', 'full_name', 'phone', 'updated_at'])->toArray(), JSON_THROW_ON_ERROR)),
            'payroll' => hash('sha256', json_encode([PayrollRun::query()->orderBy('id')->get(['id', 'period', 'status', 'updated_at'])->toArray(), PayrollItem::query()->orderBy('id')->get(['id', 'period', 'work_assignment_id', 'gross_cents', 'net_cents', 'payment_status'])->toArray()], JSON_THROW_ON_ERROR)),
            'payments' => hash('sha256', json_encode(DB::table('employee_payments')->orderBy('id')->get()->toArray(), JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $before
     * @return array{ok: bool, checks: array<string, bool>, problems: list<string>}
     */
    private function verify(array $plan, array $before): array
    {
        $problems = [];
        $checks = [];
        $after = $this->snapshot($plan);

        foreach ($before as $name => $hash) {
            $checks['unchanged: '.str_replace('_', ' ', $name)] = $after[$name] === $hash;

            if ($after[$name] !== $hash) {
                $problems[] = "{$name} changed, which this correction must never do.";
            }
        }

        $a = EmployeeWorkAssignment::query()->findOrFail($plan['assignment_id']);
        $active = EmployeeWorkAssignment::query()->where('status', WorkAssignmentStatus::Active)->where('employee_id', $a->employee_id)->get();
        $double = [];

        foreach ($active->groupBy(fn (EmployeeWorkAssignment $x): string => (string) ($x->client_company_id ?? 'x')) as $group) {
            $list = $group->values();

            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    if ($this->rangesOverlap($list[$i]->start_date->toDateString(), $list[$i]->end_date?->toDateString(), $list[$j]->start_date->toDateString(), $list[$j]->end_date?->toDateString())) {
                        $double[] = "#{$list[$i]->id} and #{$list[$j]->id}";
                    }
                }
            }
        }

        $checks['no employee holds two overlapping assignments at the same company'] = $double === [];

        if ($double !== []) {
            $problems[] = 'Overlapping assignments of one employee at one company: '.implode('; ', $double);
        }

        $windows = AssignmentAbsence::query()->where('work_assignment_id', $a->id)->orderBy('absent_from')->get();
        $cycleOk = true;

        foreach ($windows as $i => $w) {
            $from = $w->absent_from->toDateString();
            $to = $w->returned_on?->copy()->subDay()->toDateString();

            if ($from !== $w->last_working_day->copy()->addDay()->toDateString() || ($w->returned_on !== null && $w->returned_on->toDateString() < $from)) {
                $cycleOk = false;
            }

            $next = $windows[$i + 1] ?? null;

            if ($next !== null && ($to === null || $to >= $next->absent_from->toDateString())) {
                $cycleOk = false;
            }
        }

        $checks['absence windows are consistent and do not overlap'] = $cycleOk;

        if (! $cycleOk) {
            $problems[] = 'The absence windows of this assignment are inconsistent.';
        }

        return ['ok' => $problems === [], 'checks' => $checks, 'problems' => $problems];
    }

    // ------------------------------------------------------------------ payroll impact

    /**
     * @param  list<string>  $periods
     * @param  list<int>  $employeeIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function lines(array $periods, array $employeeIds): array
    {
        $out = [];

        foreach ($periods as $period) {
            $out[$period] = [];

            foreach ($this->calculator->calculate($period, $employeeIds === [] ? null : $employeeIds, false)['items'] as $item) {
                $out[$period][$item['work_assignment_id']] = $this->line($item['employee_id'], $item['employee_number'], $item['employee_name'], $item['eligible_days'], (int) $item['gross_cents'], (int) $item['net_cents']);
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function line(int $employeeId, ?string $number, ?string $name, int $days, int $gross, int $net): array
    {
        return ['employee_id' => $employeeId, 'employee_number' => $number, 'employee_name' => $name, 'days' => $days, 'gross_cents' => $gross, 'net_cents' => $net];
    }

    /**
     * @param  array<int, array<string, mixed>>  $before
     * @param  array<int, array<string, mixed>>  $after
     * @return array<string, mixed>
     */
    private function compare(array $before, array $after): array
    {
        $rows = [];
        $byEmployee = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $assignmentId) {
            $b = $before[$assignmentId] ?? null;
            $f = $after[$assignmentId] ?? null;
            $rows[] = [
                'assignment_id' => $assignmentId, 'employee_number' => $f['employee_number'] ?? $b['employee_number'] ?? null, 'employee_name' => $f['employee_name'] ?? $b['employee_name'] ?? null,
                'before_days' => $b['days'] ?? null, 'before_net_cents' => $b['net_cents'] ?? null, 'after_days' => $f['days'] ?? null, 'after_net_cents' => $f['net_cents'] ?? null,
                'difference_cents' => ($f['net_cents'] ?? 0) - ($b['net_cents'] ?? 0),
                'change' => $b === null ? 'new line' : ($f === null ? 'line removed' : ($b['net_cents'] === $f['net_cents'] && $b['days'] === $f['days'] ? 'same' : 'changed')),
            ];
            $eid = ($f ?? $b)['employee_id'];
            $byEmployee[$eid]['name'] = ($f ?? $b)['employee_name'];
            $byEmployee[$eid]['before'] = ($byEmployee[$eid]['before'] ?? 0) + ($b['net_cents'] ?? 0);
            $byEmployee[$eid]['after'] = ($byEmployee[$eid]['after'] ?? 0) + ($f['net_cents'] ?? 0);
        }

        $changedEmployees = array_values(array_filter(array_map(fn (int $id, array $e): array => ['employee_id' => $id, 'employee_name' => $e['name'], 'before_net_cents' => $e['before'], 'after_net_cents' => $e['after'], 'difference_cents' => $e['after'] - $e['before']], array_keys($byEmployee), $byEmployee), fn (array $e): bool => $e['difference_cents'] !== 0));
        usort($rows, fn (array $x, array $y): int => $x['assignment_id'] <=> $y['assignment_id']);

        return [
            'lines' => $rows, 'changed_employees' => $changedEmployees,
            'before_total_cents' => (int) array_sum(array_column($before, 'net_cents')), 'after_total_cents' => (int) array_sum(array_column($after, 'net_cents')),
            'difference_cents' => (int) array_sum(array_column($after, 'net_cents')) - (int) array_sum(array_column($before, 'net_cents')),
            'rounding' => 'each line is the exact fraction of the month rounded half-up once; lines that share a position share the cent remainder (the production calculator)',
        ];
    }

    /**
     * Months whose payroll is calculated before and after: the plan's own months plus the month before and after any single-month correction, to prove
     * nothing outside the selected month changes.
     *
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    private function impactPeriods(array $plan): array
    {
        $periods = $plan['periods'];

        if (in_array($plan['workflow'], [self::CORRECT_DATES, self::CORRECT_ABSENCE], true) && $plan['month'] !== null) {
            $m = CarbonImmutable::createFromFormat('!Y-m', $plan['month']);
            $periods = [$m->subMonth()->format('Y-m'), ...$periods, $m->addMonth()->format('Y-m')];
        }

        return array_values(array_unique($periods));
    }

    // ------------------------------------------------------------------ blockers

    /**
     * @param  list<string>  $periods
     * @param  list<int>  $employeeIds
     * @return list<array<string, string>>
     */
    private function payrollBlockers(array $periods, array $employeeIds): array
    {
        $blockers = [];
        $locked = [];
        $reviewed = [];

        foreach (PayrollRun::query()->whereIn('period', $periods === [] ? ['0000-00'] : $periods)->get() as $run) {
            if (in_array(PayrollSections::lockStatus($run), PayrollPeriodLock::LOCKED_STATUSES, true)) {
                $locked[] = $run->period.' ('.PayrollSections::lockStatus($run).')';
            } elseif ($run->status === PayrollRun::STATUS_REVIEWED) {
                $reviewed[] = $run->period;
            }

            $paid = PayrollItem::query()->where('payroll_run_id', $run->id)->where(fn ($q) => $q->where('payment_status', '!=', 'unpaid')->orWhereNotNull('employee_payment_id'))->count();

            if ($paid > 0) {
                $blockers[] = $this->b('PAYMENT_RECORDED', "{$paid} payroll line(s) of {$run->period} are marked paid. Paid payroll is never changed here.", '/hr/payroll');
            }
        }

        if ($locked !== []) {
            $blockers[] = $this->b('PAYROLL_LOCKED', 'Payroll for '.implode(', ', $locked).' is approved or finalized. The Recovery Center never changes closed payroll: use the existing Super Admin payroll correction (a reason is required and the closed month is never rewritten).', '/hr/payroll');
        }

        if ($reviewed !== []) {
            $blockers[] = $this->b('PAYROLL_REVIEWED', 'Payroll for '.implode(', ', $reviewed).' is reviewed. Move it back to a draft first (Generate on the Payroll page); a correction under a reviewed payroll would make the review wrong.', '/hr/payroll');
        }

        if ($periods !== [] && $employeeIds !== []) {
            $first = min($periods).'-01';
            $last = CarbonImmutable::createFromFormat('!Y-m', max($periods))->endOfMonth()->toDateString();
            $payments = DB::table('employee_payments')->whereIn('employee_id', $employeeIds)->whereBetween('payment_date', [$first, $last])->count();

            if ($payments > 0) {
                $blockers[] = $this->b('PAYMENT_RECORDED', "{$payments} payment(s) are recorded for these employees in the affected months. Paid payroll is never changed here.", '/hr/payroll');
            }
        }

        return $blockers;
    }

    /** @return list<array<string, string>> */
    private function employeeBlockers(EmployeeWorkAssignment $a): array
    {
        $employee = $a->employee;

        if ($employee === null || $employee->trashed()) {
            return [$this->b('EMPLOYEE_MISSING', 'The employee record is missing or archived.')];
        }

        if ($employee->status->value !== 'active') {
            return [$this->b('EMPLOYEE_NOT_ACTIVE', "The employee is {$employee->status->value}. An assignment of an employee who is not active cannot be reopened or restored: rehire or activate the employee first.", '/hr/employees/'.$employee->id)];
        }

        return [];
    }

    /** @return list<array<string, string>> */
    private function absenceBlockers(EmployeeWorkAssignment $a): array
    {
        return AssignmentAbsence::query()->where('work_assignment_id', $a->id)->exists()
            ? [$this->b('USES_REPLACEMENT_CYCLE', 'This assignment has absence records (a Replacement cycle). Use "Correct a Replacement cycle", or Return Original Employee / Replace Employee, so the cycle stays consistent.', '/hr/companies/'.$a->client_company_id.'/employees')]
            : [];
    }

    /** @return list<array<string, string>> */
    private function successorBlockers(EmployeeWorkAssignment $a): array
    {
        // The same definition as the Replace / Stop / edit paths: the Replacements of finished cover cycles are history, not someone who takes over.
        $ids = PositionSuccession::successorsOf($a)->pluck('id')->all();

        return $ids === [] ? [] : [$this->b('SUCCESSOR_EXISTS', 'Assignment(s) #'.implode(', #', $ids).' take over this one. Reopening it would pay two people for the same position and day: retire or end those first (Replace / Return / Stop on the company page), then reopen.', '/hr/companies/'.$a->client_company_id.'/employees')];
    }

    /** @return list<array<string, string>> */
    private function overlapBlockers(EmployeeWorkAssignment $a, string $from, ?string $to): array
    {
        $others = EmployeeWorkAssignment::query()
            ->where('employee_id', $a->employee_id)->where('id', '!=', $a->id)->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->when($a->client_company_id === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $a->client_company_id))
            ->get()
            ->filter(fn (EmployeeWorkAssignment $o): bool => $this->rangesOverlap($o->start_date->toDateString(), $o->end_date?->toDateString(), $from, $to));

        return $others->isEmpty() ? [] : [$this->b('OVERLAPPING_ASSIGNMENT', 'The same employee already has assignment(s) #'.$others->pluck('id')->implode(', #').' at this company on those days; two open assignments would pay the person twice.')];
    }

    private function isBoundCover(EmployeeWorkAssignment $a): bool
    {
        return ($a->assignment_type ?? AssignmentType::Permanent) === AssignmentType::Replacement && AbsenceCycles::ofReplacement($a) !== null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  list<array<string, string>>  $blockers  receives TOO_MANY_MONTHS
     * @return list<string>
     */
    private function periodsFrom(string $firstMonth, array &$blockers): array
    {
        // Every month from the first affected one to the latest payroll run or the current month, whichever is later.
        $end = max((string) PayrollRun::query()->max('period'), now()->format('Y-m'), $firstMonth);
        $periods = [];

        for ($m = CarbonImmutable::createFromFormat('!Y-m', $firstMonth); $m->format('Y-m') <= $end; $m = $m->addMonth()) {
            $periods[] = $m->format('Y-m');
        }

        if (count($periods) > self::MAX_PERIODS) {
            $blockers[] = $this->b('TOO_MANY_MONTHS', 'The correction reaches more than '.self::MAX_PERIODS.' months of payroll, which is too far back to preview safely.');

            return array_slice($periods, 0, self::MAX_PERIODS);
        }

        return $periods;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function normalizedParams(string $workflow, array $params): array
    {
        $keys = match ($workflow) {
            self::CORRECT_DATES => ['month', 'start_date', 'end_date'],
            self::CORRECT_ABSENCE => ['month', 'absence_id', 'last_working_day', 'returned_on'],
            default => [],
        };
        $out = [];

        foreach ($keys as $k) {
            $out[$k] = isset($params[$k]) && $params[$k] !== '' ? (string) $params[$k] : null;
        }

        return $out;
    }

    private function validMonth(string $month): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m', $month)->format('Y-m') === $month;
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertMonth(string $month): void
    {
        if (! $this->validMonth($month)) {
            $this->fail('The month must be written YYYY-MM.', 'INVALID_MONTH', 422);
        }
    }

    private function rangesOverlap(string $aFrom, ?string $aTo, string $bFrom, ?string $bTo): bool
    {
        return $aFrom <= ($bTo ?? '9999-12-31') && $bFrom <= ($aTo ?? '9999-12-31');
    }

    /** @return array<string, string> */
    private function b(string $code, string $message, ?string $link = null): array
    {
        return array_filter(['code' => $code, 'message' => $message, 'link' => $link], fn (?string $v): bool => $v !== null);
    }

    /**
     * @param  list<array<string, string>>  $blockers
     * @return list<array<string, string>>
     */
    private function unique(array $blockers): array
    {
        $seen = [];

        return array_values(array_filter($blockers, function (array $b) use (&$seen): bool {
            $key = $b['code'].'|'.$b['message'];

            return ! isset($seen[$key]) && ($seen[$key] = true);
        }));
    }

    private function messageOf(HttpResponseException $e): string
    {
        $data = $e->getResponse()->getContent();
        $decoded = json_decode((string) $data, true);

        return is_array($decoded) ? (string) ($decoded['message'] ?? 'The existing business rules refuse this change.') : 'The existing business rules refuse this change.';
    }

    private function fail(string $message, string $code, int $status): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }

    // ------------------------------------------------------------------ preview token

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>|null  $impact
     */
    private function makeToken(array $plan, ?array $impact, Admin $actor): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['p' => $plan['plan_hash'], 'i' => $impact['hash'] ?? '', 'a' => $actor->id, 'w' => $plan['workflow'], 'e' => now()->addMinutes(self::TOKEN_MINUTES)->getTimestamp()], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /** @return array{p: string, i: string, a: int, w: string, e: int} */
    private function readToken(string $token, Admin $actor, string $workflow): array
    {
        [$payload, $sig] = array_pad(explode('.', $token, 2), 2, '');

        if ($payload === '' || ! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $sig)) {
            $this->fail('The preview token is not valid. Preview the correction again.', 'PREVIEW_TOKEN_INVALID', 422);
        }

        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        if (! is_array($claims) || ($claims['a'] ?? null) !== $actor->id || ($claims['w'] ?? null) !== $workflow) {
            $this->fail('The preview token belongs to another correction. Preview the correction again.', 'PREVIEW_TOKEN_INVALID', 422);
        }

        if (($claims['e'] ?? 0) < now()->getTimestamp()) {
            $this->fail('The preview expired. Preview the correction again.', 'PREVIEW_EXPIRED', 409);
        }

        return $claims;
    }

    /**
     * @param  array{p: string, i: string, a: int, w: string, e: int}  $claims
     * @return array{correction: RecoveryCorrection, replayed: bool}
     */
    private function replay(RecoveryCorrection $existing, array $claims): array
    {
        if ($existing->plan_hash !== $claims['p']) {
            $this->fail('This idempotency key was already used for a different correction.', 'IDEMPOTENCY_KEY_REUSED', 409);
        }

        return ['correction' => $existing, 'replayed' => true];
    }
}
