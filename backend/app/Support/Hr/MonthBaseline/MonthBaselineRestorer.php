<?php

namespace App\Support\Hr\MonthBaseline;

use App\Enums\AssignmentType;
use App\Enums\AuditAction;
use App\Enums\WorkAssignmentStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentSalaryHistory;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use App\Support\Hr\Payroll\PayrollRunCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Month baseline restart: puts ONE calendar month's assignments back to the approved Excel baseline and retires everything entered after it, so that
 * HR can enter the month's real events again from a clean start.
 *
 * What it does, and only this:
 *  - a baseline Permanent assignment that was ended (or given an end date) inside the month is reopened under the SAME id;
 *  - every other assignment that overlaps the month and is not part of the baseline (Replacements, the Permanent rows that followed them) is retired
 *    by setting its status to Cancelled - a row that payroll, capacity and duplicate checks ignore, and that stays in the database with its salary
 *    history and audit trail. Nothing is deleted and no row is created;
 *  - an assignment the owner confirms as Excel-consistent (`$keep`) is left exactly as it is;
 *  - employees, companies, source data, other months, payroll runs and payments are never touched.
 *
 * The month is exactly the calendar month asked for. A baseline row whose start date is not the first day of that month, a record whose dates lie
 * outside it, or a change that would have to reach another month is a BLOCKER, never silently widened. A month whose payroll is approved, finalized or
 * paid is a blocker too (the existing Super Admin closed-payroll correction is the separate procedure for those).
 *
 * plan() reads only. dryRun() applies the plan inside a transaction that is ALWAYS rolled back, verifies it, and recalculates the month's payroll with
 * the production calculator, so the preview is the real calculation. apply() is the only writer and runs inside the caller's transaction.
 */
final class MonthBaselineRestorer
{
    public function __construct(private readonly PayrollRunCalculator $calculator = new PayrollRunCalculator) {}

    /**
     * @param  list<array<string, string>>  $baseline  rows of the approved plan files (source_row, employee_id, employee_number, company, salary, start_date)
     * @param  list<int>  $keep  assignment ids the owner confirmed as Excel-consistent entries
     * @return array<string, mixed>
     */
    public function plan(string $month, array $baseline, array $keep = [], string $source = 'approved Excel baseline'): array
    {
        try {
            $first = CarbonImmutable::createFromFormat('!Y-m', $month);
        } catch (\Throwable) {
            $first = false;
        }

        if ($first === false || $first->format('Y-m') !== $month) {
            throw new RuntimeException('The month must be written YYYY-MM.');
        }

        $firstDay = $first->toDateString();
        $lastDay = $first->endOfMonth()->toDateString();

        $assignments = EmployeeWorkAssignment::query()->with(['employee', 'clientCompany'])->orderBy('id')->get();
        $items = [];
        $blockers = [];
        $warnings = [];
        $matched = [];
        $expectedCents = 0;
        $seen = [];

        foreach ($baseline as $row) {
            $pair = $row['employee_id'].'|'.self::norm($row['company']);

            if (isset($seen[$pair])) {
                $items[] = ['assignment_id' => $seen[$pair], 'employee_id' => (int) $row['employee_id'], 'employee_number' => $row['employee_number'], 'employee_name' => '', 'company' => $row['company'], 'action' => 'duplicate_row', 'reason' => "The baseline lists this employee and company again (source row {$row['source_row']}); one assignment serves both rows.", 'before' => [], 'after' => []];

                continue;
            }

            if ($row['start_date'] !== $firstDay) {
                $blockers[] = ['code' => 'BASELINE_START_NOT_FIRST_DAY', 'message' => "Baseline row {$row['source_row']} ({$row['employee_number']}) starts on {$row['start_date']}, not on {$firstDay}. This restart only restores a baseline that starts on the first day of {$month}."];

                continue;
            }

            $candidates = $assignments->filter(fn (EmployeeWorkAssignment $a): bool => $a->employee_id === (int) $row['employee_id']
                && self::norm($a->clientCompany?->name ?? '') === self::norm($row['company'])
                && ($a->assignment_type ?? AssignmentType::Permanent) === AssignmentType::Permanent
                && $a->start_date->toDateString() === $row['start_date']);

            /** @var EmployeeWorkAssignment|null $assignment */
            $assignment = $candidates->sortBy('id')->first();

            if ($assignment === null) {
                $blockers[] = ['code' => 'BASELINE_ASSIGNMENT_MISSING', 'message' => "Baseline row {$row['source_row']} ({$row['employee_number']}, {$row['company']}): no Permanent assignment starting {$row['start_date']} exists. Nothing is created automatically."];

                continue;
            }

            $seen[$pair] = $assignment->id;
            $matched[$assignment->id] = true;
            $expectedCents += (int) round(((float) $row['salary']) * 100);
            $employee = $assignment->employee;

            if ($employee === null || $employee->trashed()) {
                $blockers[] = ['code' => 'BASELINE_EMPLOYEE_MISSING', 'message' => "Assignment #{$assignment->id}: its employee record is missing or archived."];

                continue;
            }

            if ($employee->status->value !== 'active') {
                $warnings[] = ['code' => 'BASELINE_EMPLOYEE_NOT_ACTIVE', 'message' => "Assignment #{$assignment->id} ({$employee->employee_number}): the employee is {$employee->status->value}; the employee record is not changed by this restart."];
            }

            if ($employee->employment_type !== 'permanent') {
                $warnings[] = ['code' => 'BASELINE_EMPLOYEE_NOT_PERMANENT', 'message' => "Assignment #{$assignment->id} ({$employee->employee_number}): the employee is classified {$employee->employment_type}; the classification is not changed by this restart."];
            }

            $before = $this->state($assignment);
            $action = 'intact';
            $reason = 'Already the baseline assignment.';
            $after = $before;

            if ($assignment->status === WorkAssignmentStatus::Cancelled) {
                $blockers[] = ['code' => 'BASELINE_ASSIGNMENT_CANCELLED', 'message' => "Baseline assignment #{$assignment->id} is cancelled; reopening a cancelled row is not part of this restart."];
                $action = 'blocked';
                $reason = 'Cancelled; not reopened by this restart.';
            } elseif ($assignment->end_date !== null || $assignment->status !== WorkAssignmentStatus::Active) {
                $end = $assignment->end_date?->toDateString();

                if ($end !== null && ($end < $firstDay || $end > $lastDay)) {
                    $blockers[] = ['code' => 'END_OUTSIDE_MONTH', 'message' => "Baseline assignment #{$assignment->id} ends on {$end}, outside {$month}; changing it would reach another month, so it is blocked."];
                    $action = 'blocked';
                    $reason = "Ends on {$end}, outside {$month}; not changed.";
                } else {
                    $action = 'reopen';
                    $reason = 'Ended during '.$month.($end === null ? '' : " (last day {$end})").'; reopened under the same assignment id.';
                    $after = [...$before, 'status' => 'active', 'end_date' => null];
                }
            }

            // A salary that differs from the Excel (assignment #95: the owner decided to keep $200 effective 31 October) is only REPORTED: a salary change
            // also reaches later months, so this restart never edits salaries or salary history.
            if ((float) $assignment->salary_amount !== (float) $row['salary']) {
                $items[] = ['assignment_id' => $assignment->id, 'employee_id' => $assignment->employee_id, 'employee_number' => $assignment->employee?->employee_number, 'employee_name' => $assignment->employee?->full_name, 'company' => $assignment->clientCompany?->name, 'action' => 'salary_differs', 'reason' => "The salary is now {$assignment->salary_amount}; the Excel baseline says {$row['salary']}. Left exactly as it is (owner decision): salaries and salary history are never changed by this restart.", 'before' => ['salary' => (string) $assignment->salary_amount], 'after' => ['salary' => (string) $assignment->salary_amount]];
            }

            $items[] = ['assignment_id' => $assignment->id, 'employee_id' => $employee->id, 'employee_number' => $employee->employee_number, 'employee_name' => $employee->full_name, 'company' => $assignment->clientCompany?->name, 'action' => $action, 'reason' => $reason, 'before' => $before, 'after' => $after];
        }

        $keepIds = array_map('intval', $keep);

        foreach ($assignments as $assignment) {
            if (isset($matched[$assignment->id]) || $assignment->status === WorkAssignmentStatus::Cancelled) {
                continue;
            }

            $start = $assignment->start_date->toDateString();
            $end = $assignment->end_date?->toDateString();
            $overlaps = $start <= $lastDay && ($end === null || $end >= $firstDay);
            $before = $this->state($assignment);

            if (! $overlaps) {
                if ($assignment->replaces_assignment_id !== null && isset($matched[$assignment->replaces_assignment_id]) === false && $this->willBeRetired($assignments, $assignment->replaces_assignment_id, $keepIds, $matched, $firstDay, $lastDay)) {
                    $blockers[] = ['code' => 'DEPENDS_ON_RETIRED', 'message' => "Assignment #{$assignment->id} ({$start}) lies outside {$month} but replaces an assignment this restart retires. It would be left pointing at a cancelled row, so the restart is blocked."];
                }

                continue;
            }

            if (in_array($assignment->id, $keepIds, true)) {
                $items[] = ['assignment_id' => $assignment->id, 'employee_id' => $assignment->employee_id, 'employee_number' => $assignment->employee?->employee_number, 'employee_name' => $assignment->employee?->full_name, 'company' => $assignment->clientCompany?->name, 'action' => 'keep', 'reason' => 'Confirmed by the owner as an Excel-consistent entry; left exactly as it is.', 'before' => $before, 'after' => $before];

                if ($start < $firstDay || ($end !== null && $end > $lastDay)) {
                    $warnings[] = ['code' => 'KEPT_ASSIGNMENT_SPANS_MONTH', 'message' => "Kept assignment #{$assignment->id} starts {$start}".($end === null ? ' and is open-ended' : " and ends {$end}").'.'];
                }

                continue;
            }

            if ($start < $firstDay) {
                $blockers[] = ['code' => 'START_OUTSIDE_MONTH', 'message' => "Assignment #{$assignment->id} starts {$start}, before {$month}, and is not part of the baseline. Retiring it would reach an earlier month, so it is blocked."];

                continue;
            }

            $items[] = ['assignment_id' => $assignment->id, 'employee_id' => $assignment->employee_id, 'employee_number' => $assignment->employee?->employee_number, 'employee_name' => $assignment->employee?->full_name, 'company' => $assignment->clientCompany?->name, 'action' => 'retire', 'reason' => 'Entered after the baseline and not part of it ('.($assignment->assignment_type?->value ?? 'permanent').' from '.$start.($end === null ? ', open-ended' : " to {$end}").'); retired by cancelling the row, which stays in the database.', 'before' => $before, 'after' => [...$before, 'status' => 'cancelled']];
        }

        foreach ($keepIds as $id) {
            if (! $assignments->contains('id', $id)) {
                $blockers[] = ['code' => 'KEEP_ASSIGNMENT_NOT_FOUND', 'message' => "The assignment to keep (#{$id}) does not exist."];
            }
        }

        $targeted = array_values(array_unique(array_map(fn (array $i): int => (int) $i['assignment_id'], array_filter($items, fn (array $i): bool => in_array($i['action'], ['reopen', 'retire'], true)))));
        $blockers = [...$blockers, ...$this->payrollBlockers($month, $targeted, $items)];

        $plan = [
            'month' => $month, 'first_day' => $firstDay, 'last_day' => $lastDay, 'source' => $source,
            'baseline_rows' => count($baseline), 'expected_baseline_cents' => $expectedCents,
            'items' => $items, 'blockers' => $blockers, 'warnings' => $warnings,
        ];
        $plan['summary'] = array_count_values(array_column($items, 'action'));
        $plan['plan_hash'] = hash('sha256', json_encode([$month, $keepIds, array_map(fn (array $i): array => [$i['assignment_id'], $i['action'], $i['before'], $i['after']], $items), $blockers], JSON_THROW_ON_ERROR));

        return $plan;
    }

    /**
     * Applies the plan inside a transaction that is always rolled back, verifies it, and recalculates the month's payroll with the production calculator.
     * Nothing is written: no audit events are recorded and no rows are created, so not even a sequence advances.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function dryRun(array $plan): array
    {
        $month = $plan['month'];
        $current = $this->currentLines($month);
        $guard = $this->snapshot($plan);

        DB::beginTransaction();

        try {
            $this->apply($plan, null, false, '', '');
            $verification = $this->verify($plan, $guard);
            $proposed = $this->proposedLines($month);
        } finally {
            DB::rollBack();
        }

        return ['verification' => $verification, 'payroll' => $this->compare($current, $proposed, $plan)];
    }

    /**
     * The only writer. Runs inside the caller's transaction (the caller locks, re-plans and checks the plan hash first).
     *
     * @param  array<string, mixed>  $plan
     */
    public function apply(array $plan, ?Admin $actor, bool $audit, string $reason, string $batch): void
    {
        $note = "October restart: restored to the {$plan['source']} for {$plan['month']}";

        foreach ($plan['items'] as $item) {
            if (! in_array($item['action'], ['reopen', 'retire'], true)) {
                continue;
            }

            $assignment = EmployeeWorkAssignment::query()->lockForUpdate()->findOrFail($item['assignment_id']);

            if ($item['action'] === 'reopen') {
                $assignment->update(['status' => WorkAssignmentStatus::Active, 'end_date' => null, 'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '')."{$note} (was ended ".($item['before']['end_date'] ?? 'without a date').').')]);
                $this->record($audit, $actor, $item, $reason, $batch, 'month_baseline_restored', "Work assignment #{$assignment->id} restored to the baseline for {$plan['month']} (reopened under the same id).");
            } else {
                $assignment->update(['status' => WorkAssignmentStatus::Cancelled, 'notes' => trim(($assignment->notes ? $assignment->notes."\n" : '')."{$note}: this entry is not part of the baseline and was retired (cancelled, not deleted).")]);
                $this->record($audit, $actor, $item, $reason, $batch, 'month_change_retired', "Work assignment #{$assignment->id} retired for the {$plan['month']} restart (cancelled, not deleted).");
            }
        }
    }

    /**
     * Records the batch summary after apply().
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $result
     */
    public function recordBatch(array $plan, Admin $actor, string $reason, string $batch, array $result): void
    {
        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: "Month baseline restart applied for {$plan['month']}: ".json_encode($plan['summary']).'. '.$reason,
            metadata: ['event' => 'month_baseline_restart', 'batch' => $batch, 'month' => $plan['month'], 'reason' => $reason, 'plan_hash' => $plan['plan_hash'], 'summary' => $plan['summary'], 'payroll_status' => $result['payroll_status'] ?? null, 'payroll_regenerated' => false],
        ));
    }

    /**
     * Everything the restart promises to leave alone, fingerprinted before it runs.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, string>
     */
    public function snapshot(array $plan): array
    {
        $targeted = array_values(array_unique(array_map(fn (array $i): int => (int) $i['assignment_id'], array_filter($plan['items'], fn (array $i): bool => in_array($i['action'], ['reopen', 'retire'], true)))));
        $month = $plan['month'];

        return [
            'untargeted_assignments' => hash('sha256', json_encode(EmployeeWorkAssignment::query()->whereNotIn('id', $targeted === [] ? [0] : $targeted)->orderBy('id')->get(['id', 'employee_id', 'client_company_id', 'assignment_type', 'replaces_assignment_id', 'start_date', 'end_date', 'status', 'salary_amount', 'position_id', 'shift', 'notes'])->toArray(), JSON_THROW_ON_ERROR)),
            'untargeted_salary_histories' => hash('sha256', json_encode(AssignmentSalaryHistory::query()->whereNotIn('work_assignment_id', $targeted === [] ? [0] : $targeted)->orderBy('id')->get(['id', 'work_assignment_id', 'effective_from', 'amount'])->toArray(), JSON_THROW_ON_ERROR)),
            'employees' => hash('sha256', json_encode(Employee::query()->withTrashed()->orderBy('id')->get(['id', 'status', 'employment_type', 'full_name', 'phone', 'updated_at'])->toArray(), JSON_THROW_ON_ERROR)),
            'other_months_payroll' => hash('sha256', json_encode([
                PayrollRun::query()->where('period', '!=', $month)->orderBy('id')->get(['id', 'period', 'status', 'updated_at'])->toArray(),
                PayrollItem::query()->where('period', '!=', $month)->orderBy('id')->get(['id', 'period', 'work_assignment_id', 'gross_cents', 'net_cents', 'payment_status'])->toArray(),
            ], JSON_THROW_ON_ERROR)),
            'payments' => hash('sha256', json_encode(DB::table('employee_payments')->orderBy('id')->get()->toArray(), JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Checks that the restart did exactly what the plan says and nothing else. Used on the real run (a failure rolls it back) and on the dry run.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $before  snapshot() taken before apply()
     * @return array{ok: bool, checks: array<string, bool>, problems: list<string>}
     */
    public function verify(array $plan, array $before): array
    {
        $problems = [];
        $checks = [];
        $after = $this->snapshot($plan);

        foreach ($before as $name => $hash) {
            $checks['unchanged: '.$name] = $after[$name] === $hash;

            if ($after[$name] !== $hash) {
                $problems[] = "{$name} changed, which this restart must never do.";
            }
        }

        $firstDay = $plan['first_day'];
        $lastDay = $plan['last_day'];
        $rows = EmployeeWorkAssignment::query()->whereIn('id', array_map(fn (array $i): int => (int) $i['assignment_id'], $plan['items']))->get()->keyBy('id');

        foreach ($plan['items'] as $item) {
            $row = $rows->get($item['assignment_id']);

            if ($row === null) {
                continue;
            }

            $ok = match ($item['action']) {
                'reopen', 'intact' => $row->status === WorkAssignmentStatus::Active && $row->end_date === null && $row->start_date->toDateString() === $firstDay,
                'retire' => $row->status === WorkAssignmentStatus::Cancelled,
                'keep' => $row->status === WorkAssignmentStatus::Active,
                default => true,
            };

            if (! $ok) {
                $problems[] = "Assignment #{$item['assignment_id']} is not in the state the plan promised ({$item['action']}).";
            }

            $checks["assignment #{$item['assignment_id']} {$item['action']}"] = $ok;
        }

        // Nobody may be paid twice for the same position and day: one open assignment per employee and company, and nothing active that replaces a retired row.
        $active = EmployeeWorkAssignment::query()->where('status', WorkAssignmentStatus::Active)->get();
        $double = [];

        foreach ($active->groupBy(fn (EmployeeWorkAssignment $a): string => $a->employee_id.'|'.($a->client_company_id ?? 'x')) as $group) {
            $list = $group->values();

            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    if ($this->overlap($list[$i], $list[$j], $firstDay, $lastDay)) {
                        $double[] = "#{$list[$i]->id} and #{$list[$j]->id}";
                    }
                }
            }
        }

        $checks['no employee holds two overlapping assignments at the same company'] = $double === [];

        if ($double !== []) {
            $problems[] = 'Overlapping assignments of one employee at one company: '.implode('; ', $double);
        }

        $orphans = $active->filter(fn (EmployeeWorkAssignment $a): bool => $a->replaces_assignment_id !== null && ($rows->get($a->replaces_assignment_id)?->status === WorkAssignmentStatus::Cancelled || EmployeeWorkAssignment::query()->whereKey($a->replaces_assignment_id)->where('status', WorkAssignmentStatus::Cancelled)->exists()));
        $checks['no active assignment replaces a retired one'] = $orphans->isEmpty();

        if ($orphans->isNotEmpty()) {
            $problems[] = 'Active assignment(s) still replace a retired one: #'.$orphans->pluck('id')->implode(', #');
        }

        return ['ok' => $problems === [], 'checks' => $checks, 'problems' => $problems];
    }

    /**
     * The month's payroll lines as they stand now (the draft run, if there is one), keyed by assignment id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function currentLines(string $month): array
    {
        $run = PayrollRun::query()->where('period', $month)->first();

        if ($run === null) {
            return [];
        }

        return PayrollItem::query()->where('payroll_run_id', $run->id)->whereNull('voided_at')->orderBy('id')->get()
            ->mapWithKeys(fn (PayrollItem $i): array => [$i->work_assignment_id => ['employee_id' => $i->employee_id, 'employee_number' => $i->employee_number, 'employee_name' => $i->employee_name, 'days' => $i->eligible_days, 'gross_cents' => (int) $i->gross_cents, 'net_cents' => (int) $i->net_cents]])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function proposedLines(string $month): array
    {
        $lines = [];

        foreach ($this->calculator->calculate($month)['items'] as $item) {
            $lines[$item['work_assignment_id']] = ['employee_id' => $item['employee_id'], 'employee_number' => $item['employee_number'], 'employee_name' => $item['employee_name'], 'days' => $item['eligible_days'], 'gross_cents' => (int) $item['gross_cents'], 'net_cents' => (int) $item['net_cents']];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $current
     * @param  array<int, array<string, mixed>>  $proposed
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function compare(array $current, array $proposed, array $plan): array
    {
        $rows = [];

        foreach (array_unique([...array_keys($current), ...array_keys($proposed)]) as $assignmentId) {
            $c = $current[$assignmentId] ?? null;
            $p = $proposed[$assignmentId] ?? null;
            $rows[] = [
                'assignment_id' => $assignmentId,
                'employee_number' => $p['employee_number'] ?? $c['employee_number'] ?? '',
                'employee_name' => $p['employee_name'] ?? $c['employee_name'] ?? '',
                'current_days' => $c['days'] ?? null, 'current_net_cents' => $c['net_cents'] ?? null,
                'proposed_days' => $p['days'] ?? null, 'proposed_net_cents' => $p['net_cents'] ?? null,
                'difference_cents' => ($p['net_cents'] ?? 0) - ($c['net_cents'] ?? 0),
                'change' => $c === null ? 'new line' : ($p === null ? 'line removed' : (($c['net_cents'] === $p['net_cents'] && $c['days'] === $p['days']) ? 'same' : 'changed')),
            ];
        }

        $currentTotal = array_sum(array_column($current, 'net_cents'));
        $proposedTotal = array_sum(array_column($proposed, 'net_cents'));

        // Per employee as well: a person whose position was split over several lines is paid the same in total when the lines merge.
        $byEmployee = [];

        foreach ($current as $line) {
            $byEmployee[$line['employee_id']]['current'] = ($byEmployee[$line['employee_id']]['current'] ?? 0) + $line['net_cents'];
        }
        foreach ($proposed as $line) {
            $byEmployee[$line['employee_id']]['proposed'] = ($byEmployee[$line['employee_id']]['proposed'] ?? 0) + $line['net_cents'];
        }

        $employeesChanged = array_filter($byEmployee, fn (array $e): bool => ($e['current'] ?? 0) !== ($e['proposed'] ?? 0));

        return [
            'month' => $plan['month'], 'lines' => $rows,
            'current_lines' => count($current), 'proposed_lines' => count($proposed),
            'current_total_cents' => $currentTotal, 'proposed_total_cents' => $proposedTotal, 'difference_cents' => $proposedTotal - $currentTotal,
            'employees_whose_pay_changes' => count($employeesChanged),
            'expected_baseline_cents' => $plan['expected_baseline_cents'],
            'currency' => 'USD',
            'rounding' => 'each line is the exact fraction of the month rounded half-up once; lines that share one position share the cent remainder (the production calculator)',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(EmployeeWorkAssignment $a): array
    {
        return ['status' => $a->status->value, 'type' => ($a->assignment_type ?? AssignmentType::Permanent)->value, 'start_date' => $a->start_date->toDateString(), 'end_date' => $a->end_date?->toDateString(), 'salary' => (string) $a->salary_amount, 'replaces' => $a->replaces_assignment_id, 'position_id' => $a->position_id, 'shift' => $a->shift?->value];
    }

    /**
     * @param  list<int>  $targeted
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, string>>
     */
    private function payrollBlockers(string $month, array $targeted, array $items): array
    {
        $blockers = [];
        $run = PayrollRun::query()->where('period', $month)->first();
        $changes = array_filter($items, fn (array $i): bool => in_array($i['action'], ['reopen', 'retire'], true));

        if ($run !== null && in_array($run->status, PayrollPeriodLock::LOCKED_STATUSES, true) && $changes !== []) {
            $blockers[] = ['code' => 'PAYROLL_LOCKED', 'message' => "Payroll for {$month} is {$run->status}. This restart never changes approved or finalized payroll. The safe procedure is the existing Super Admin payroll correction (reason required, the closed month is never rewritten); nothing was changed."];
        } elseif ($run !== null && $run->status === PayrollRun::STATUS_REVIEWED && $changes !== []) {
            $blockers[] = ['code' => 'PAYROLL_REVIEWED', 'message' => "Payroll for {$month} is reviewed. Reopen or regenerate it as a draft first; this restart only works on a draft or on a month with no payroll."];
        }

        if ($run !== null) {
            $paid = PayrollItem::query()->where('payroll_run_id', $run->id)->where(fn ($q) => $q->where('payment_status', '!=', 'unpaid')->orWhereNotNull('employee_payment_id'))->count();

            if ($paid > 0) {
                $blockers[] = ['code' => 'PAYMENT_RECORDED', 'message' => "{$paid} payroll line(s) of {$month} are marked paid or linked to a payment; the restart is blocked."];
            }
        }

        if ($targeted !== []) {
            $covers = TemporaryReplacement::query()->whereIn('work_assignment_id', $targeted)->count();
            $absences = AssignmentAbsence::query()->whereIn('work_assignment_id', $targeted)->count();

            if ($covers > 0 || $absences > 0) {
                $blockers[] = ['code' => 'COVERS_OR_ABSENCES_PRESENT', 'message' => "{$covers} Temporary cover(s) and {$absences} absence record(s) are attached to the assignments this restart would change; it does not touch them, so it is blocked."];
            }

            $affectedEmployees = EmployeeWorkAssignment::query()->whereIn('id', $targeted)->pluck('employee_id')->unique()->all();
            $payments = DB::table('employee_payments')->whereIn('employee_id', $affectedEmployees)->whereBetween('payment_date', [$month.'-01', CarbonImmutable::createFromFormat('!Y-m', $month)->endOfMonth()->toDateString()])->count();

            if ($payments > 0) {
                $blockers[] = ['code' => 'PAYMENT_RECORDED', 'message' => "{$payments} payment(s) are recorded for affected employees in {$month}; the restart is blocked."];
            }
        }

        return $blockers;
    }

    /**
     * @param  Collection<int, EmployeeWorkAssignment>  $assignments
     * @param  list<int>  $keep
     * @param  array<int, bool>  $matched
     */
    private function willBeRetired($assignments, int $id, array $keep, array $matched, string $firstDay, string $lastDay): bool
    {
        $a = $assignments->firstWhere('id', $id);

        if ($a === null || isset($matched[$id]) || in_array($id, $keep, true) || $a->status === WorkAssignmentStatus::Cancelled) {
            return false;
        }

        $end = $a->end_date?->toDateString();

        return $a->start_date->toDateString() <= $lastDay && ($end === null || $end >= $firstDay);
    }

    private function overlap(EmployeeWorkAssignment $a, EmployeeWorkAssignment $b, string $firstDay, string $lastDay): bool
    {
        $aStart = max($a->start_date->toDateString(), $firstDay);
        $bStart = max($b->start_date->toDateString(), $firstDay);
        $aEnd = min($a->end_date?->toDateString() ?? $lastDay, $lastDay);
        $bEnd = min($b->end_date?->toDateString() ?? $lastDay, $lastDay);

        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function record(bool $audit, ?Admin $actor, array $item, string $reason, string $batch, string $event, string $description): void
    {
        if (! $audit) {
            return;
        }

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: $description,
            entityType: EmployeeWorkAssignment::class,
            entityId: (int) $item['assignment_id'],
            metadata: ['event' => $event, 'batch' => $batch, 'reason' => $reason, 'employee_id' => $item['employee_id'], 'before' => $item['before'], 'after' => $item['after']],
        ));
    }

    private static function norm(string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($s)) ?? '';
    }

    /**
     * A fresh batch identifier for the audit events of one run.
     */
    public static function newBatch(): string
    {
        return (string) Str::uuid();
    }
}
