<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AssignmentType;
use App\Enums\AttendanceStatus;
use App\Enums\LeaveType;
use App\Enums\WorkAssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollAdjustment;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRecoveryDeduction;
use App\Models\TemporaryReplacement;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\AssignmentChain;
use App\Support\Hr\EmploymentTypeChange;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Computes (without saving) the payroll lines for one month. Every assignment
 * of a Permanent employee that overlaps the month becomes one item. Gross pay
 * is prorated by calendar days from the effective-dated salary history.
 * Deductions are itemized and each has a unique source so it can be applied
 * only once for the period: approved Temporary replacement costs (from verified
 * workdays), penalties and advances for the period, and absence (every assignment,
 * per the approved Phase 5 rule). Penalties/advances attach to the employee's first item only, so a
 * person with two assignments is never charged twice. Pending payroll
 * adjustments targeting the period are folded in as a signed amount. Anything
 * that cannot be calculated safely (non-USD amounts, non-monthly salary) is
 * reported in `warnings` instead of being guessed.
 */
class PayrollRunCalculator
{
    public function __construct(
        private readonly ProrationCalculator $proration = new ProrationCalculator,
        private readonly ReplacementCostService $replacementCosts = new ReplacementCostService,
    ) {}

    /**
     * @param  list<int>|null  $employeeIds  limit the calculation to these employees (used by the per-employee summary); null = everyone. Period-wide orphan warnings are only produced for a full run.
     * @return array{items: list<array<string, mixed>>, warnings: list<string>, totals: array<string, int>}
     */
    /** @var array<int, string> work assignment id => the key of the company its payroll line belongs to (InputOwnership) */
    private array $lineKeys = [];

    /** @var array<int, array<string, true>> employee id => the company keys of their payroll lines this month */
    private array $employeeKeys = [];

    /**
     * Remembers which company each payroll line of the month belongs to, so every penalty, advance, unpaid leave, absence and recovery is charged to
     * the company that OWNS it (owner decision 2026-11-03), never to "the first line" and never to several.
     *
     * @param  Collection<int, EmployeeWorkAssignment>  $assignments
     */
    private function indexOwnership(Collection $assignments): void
    {
        $this->lineKeys = [];
        $this->employeeKeys = [];

        foreach ($assignments as $assignment) {
            if ($assignment->employee === null || $assignment->employee->trashed()) {
                continue;
            }

            $key = InputOwnership::lineKey($assignment);
            $this->lineKeys[$assignment->id] = $key;
            $this->employeeKeys[$assignment->employee_id][$key] = true;
        }
    }

    /**
     * The company key a record is charged to: the company it names; or, for a record from before company ownership (no company), the employee's one
     * company when they work for exactly one that month. Null = ambiguous (several companies): it is then charged NOWHERE and reported.
     */
    private function ownerKey(?int $recordCompany, int $employeeId): ?string
    {
        if ($recordCompany !== null) {
            return (string) $recordCompany;
        }

        $keys = array_keys($this->employeeKeys[$employeeId] ?? []);

        return count($keys) === 1 ? (string) $keys[0] : null;
    }

    public function calculate(string $period, ?array $employeeIds = null, bool $withRecovery = true, bool $batched = true): array
    {
        try {
            return $this->compute($period, $employeeIds, $withRecovery, $batched);
        } finally {
            $this->inputs = null;
            $this->lineKeys = [];
            $this->employeeKeys = [];
        }
    }

    /**
     * The inputs of the calculation in progress, read once for every assignment (see PayrollInputs). null = ask the database for each assignment on its
     * own, the way the calculation always worked: `$batched = false` keeps that path available so a test can prove both give exactly the same lines.
     */
    private ?PayrollInputs $inputs = null;

    /** @return array{items: list<array<string, mixed>>, warnings: list<string>, totals: array<string, int>} */
    private function compute(string $period, ?array $employeeIds, bool $withRecovery, bool $batched): array
    {
        [$first, $daysInMonth] = ProrationCalculator::month($period);
        $last = $first->modify('+'.($daysInMonth - 1).' days');
        $warnings = [];
        $items = [];
        $firstItemForEmployee = [];
        $absenceCharged = [];

        $assignments = EmployeeWorkAssignment::query()
            ->with(['employee', 'workLocation', 'clientCompany', 'workLocation.clientCompany', ...($batched ? ['salaryHistories'] : [])])
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds === [] ? [0] : $employeeIds))
            ->whereDate('start_date', '<=', $last->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $first->format('Y-m-d')))
            ->orderBy('id')
            ->get();

        $this->inputs = $batched ? PayrollInputs::load($period, $assignments, $first, $last) : null;
        $this->indexOwnership($assignments);

        // Lines that share one position (Permanent -> Replacement -> returned Permanent) are rounded together so their cents add up.
        $reconciled = $this->reconciledChainGross($period, $assignments);

        foreach ($assignments as $assignment) {
            $employee = $assignment->employee;

            if ($employee === null || $employee->trashed()) {
                continue;
            }

            // An assignment held as a Permanent until the person was changed to Temporary (after it had ended) is still a Permanent payroll line.
            if ($employee->employment_type === 'temporary' && ! EmploymentTypeChange::wasHeldAsPermanent($employee, $assignment)) {
                $warnings[] = $assignment->assignment_type === AssignmentType::Replacement
                    ? "UNRESOLVED: assignment #{$assignment->id} registers Temporary worker {$employee->employee_number} as a Replacement. No payroll line was calculated for these days; correct the classification before payroll is calculated."
                    : "Assignment #{$assignment->id} belongs to a Temporary employee ({$employee->employee_number}); Temporary pay is the replacement cost deducted from the covered Permanent employee, so no payroll line was created.";

                continue;
            }

            // The days someone else holds (a Replacement) or that the holder is away: not paid to this assignment, so nothing else (unpaid leave, an
            // absent mark, a Temporary cover's cost) may be taken from this assignment for them either.
            $heldElsewhere = $this->replacementRanges($assignment);

            try {
                $salary = new SalaryTimeline($this->salaryPeriodsOf($assignment));
                $gross = $this->proration->monthlyGross(
                    $period,
                    $salary,
                    $assignment->start_date->toDateString(),
                    $assignment->end_date?->toDateString(),
                    $heldElsewhere,
                );
            } catch (\RuntimeException $e) {
                $warnings[] = "Assignment #{$assignment->id} ({$employee->employee_number}): {$e->getMessage()}";
                $this->warnAboutUnpayableReplacementDays($assignment, $first, $last, $warnings);

                continue;
            }

            $this->warnAboutUncoveredAbsence($assignment, $first, $last, $period, $warnings);

            if ($employee->status->value !== 'active' && $assignment->status === WorkAssignmentStatus::Active && $gross['eligible_days'] > 0) {
                $warnings[] = "Employee {$employee->employee_number} is {$employee->status->value} but still has an open assignment (#{$assignment->id}) in {$period}; they were included. End the assignment if employment ended.";
            }

            if ($gross['eligible_days'] === 0) {
                continue;
            }

            $remainderCents = 0;

            if (isset($reconciled[$assignment->id])) {
                $remainderCents = $reconciled[$assignment->id]['gross_cents'] - $gross['gross_cents'];
                $gross['gross_cents'] = $reconciled[$assignment->id]['gross_cents'];
            }

            $deductions = [];
            $pendingReplacements = [];
            $coveredDates = [];

            $covers = $this->inputs?->hasAssignment($assignment->id)
                ? $this->inputs->covers($assignment->id)
                : TemporaryReplacement::query()->with('replacementEmployee')->where('work_assignment_id', $assignment->id)->get();

            foreach ($covers as $replacement) {
                $cost = $this->replacementCosts->forPeriod($replacement, $period);

                $this->warnAboutNonChargeableDays($assignment, $replacement, $cost, $warnings);

                if ($cost['covered_days'] === 0 && $cost['amount_cents'] === 0) {
                    continue;
                }

                $detail = [
                    'replacement_id' => $replacement->id,
                    'temporary_employee_id' => $replacement->replacement_employee_id,
                    'temporary_employee_number' => $replacement->replacementEmployee?->employee_number,
                    'temporary_employee_name' => $replacement->replacementEmployee?->full_name,
                    'start_date' => $replacement->start_date->toDateString(),
                    'end_date' => $replacement->end_date?->toDateString(),
                    // U8: the days that count are the cover's ACTUAL coverage period (inclusive); the ledger's verified days are attendance, shown for information.
                    'covered_days' => $cost['covered_days'],
                    'verified_days' => $cost['verified_days'],
                    'entitlement_cents' => $cost['entitlement_cents'],
                    'coverage' => $cost['coverage'],
                    'planned_end_date' => $replacement->planned_end_date?->toDateString(),
                    'daily_rate' => $cost['daily_rate'],
                    'days_in_month' => $cost['days_in_month'],
                    'charged_dates' => $cost['charged_dates'],
                    'ignored' => $cost['ignored'],
                    'not_chargeable' => $cost['not_chargeable'],
                    'amount_cents' => $cost['amount_cents'],
                ];

                if ($replacement->approved_at === null) {
                    $pendingReplacements[] = $detail;

                    continue;
                }

                // The days an approved replacement covers are deducted once, as the replacement cost (approved rule: absent day = one daily rate, which pays the replacement).
                $coveredDates = [...$coveredDates, ...$cost['charged_dates']];

                $deductions[] = [
                    'kind' => PayrollItemDeduction::KIND_REPLACEMENT,
                    'source_type' => 'temporary_replacement',
                    'source_id' => $replacement->id,
                    'amount_cents' => $cost['amount_cents'],
                    'description' => "Temporary cover by {$detail['temporary_employee_name']}: {$cost['covered_days']} covered day(s)",
                    'detail' => $detail,
                ];
            }

            $lineKey = $this->lineKeys[$assignment->id] ?? InputOwnership::lineKey($assignment);

            // A person's penalties and advances belong to a COMPANY: the first line of THAT company carries them (several lines in one company: once).
            if (! isset($firstItemForEmployee[$employee->id][$lineKey])) {
                $firstItemForEmployee[$employee->id][$lineKey] = true;
                $this->addPenaltiesAndAdvances($employee->id, $period, $lineKey, $deductions, $warnings);
            }

            $pendingLeaves = [];
            $leaveDates = $this->addUnpaidLeave($assignment, $salary, $first, $last, $daysInMonth, $deductions, $pendingLeaves, $warnings, $heldElsewhere);

            $this->addAbsence($assignment, $salary, $first, $last, $daysInMonth, $deductions, $leaveDates, $coveredDates, $absenceCharged[$employee->id][$lineKey], $heldElsewhere);

            $pending = $this->inputs?->hasAssignment($assignment->id)
                ? $this->inputs->pendingAdjustments($assignment->id)
                : PayrollAdjustment::query()
                    ->where('applies_in_period', $period)->where('status', 'pending')
                    ->whereHas('item', fn ($q) => $q->where('work_assignment_id', $assignment->id))->get();
            $adjustmentCents = (int) $pending->sum('amount_cents');

            // Release 1B: penalties and advances take at most what the line has left (net never below $0); the rest stays owed and carries on.
            $this->capOwedDeductions($deductions, $gross['gross_cents'], $adjustmentCents, (string) $employee->employee_number, $warnings);
            $otherCents = (int) collect($deductions)->where('kind', '!=', PayrollItemDeduction::KIND_REPLACEMENT)->sum('amount_cents');

            // Owner decision 2026-11-03: Temporary covers can never push a Permanent's net below zero. The Temporary's own amount stays the full cost (it is in
            // the detail); only what is taken off this line is capped (ReplacementDeductionCap).
            $this->capReplacementDeductions($deductions, $gross['gross_cents'], $otherCents, $adjustmentCents);
            $replacementCents = (int) collect($deductions)->where('kind', PayrollItemDeduction::KIND_REPLACEMENT)->sum('amount_cents');

            $company = $assignment->clientCompany ?? $assignment->workLocation?->clientCompany;

            $items[] = [
                'employee_id' => $employee->id,
                'work_assignment_id' => $assignment->id,
                'employee_name' => $employee->full_name,
                'employee_number' => $employee->employee_number,
                'company_name' => $company?->name,
                'workplace_name' => $assignment->workLocation?->name,
                'eligible_days' => $gross['eligible_days'],
                'days_in_month' => $gross['days_in_month'],
                'gross_cents' => $gross['gross_cents'],
                'replacement_deduction_cents' => $replacementCents,
                'other_deduction_cents' => $otherCents,
                'adjustment_cents' => $adjustmentCents,
                'net_cents' => $gross['gross_cents'] - $replacementCents - $otherCents + $adjustmentCents,
                'deductions' => $deductions,
                'adjustment_ids' => $pending->pluck('id')->all(),
                'calc_snapshot' => [
                    'period' => $period,
                    'currency' => PayrollMoney::CURRENCY,
                    'employment_window' => ['from' => $assignment->start_date->toDateString(), 'to' => $assignment->end_date?->toDateString()],
                    'salary_segments' => $gross['segments'],
                    'final_pay' => $this->finalPay($assignment, $employee, $first, $last, $gross),
                    'succession' => $this->succession($assignment, $period, $first, $last, $warnings),
                    'rounding' => 'exact fraction rounded half-up to the cent once per line; when several lines share one position (Permanent / Replacement / returned Permanent) the cent remainder goes to the LAST line of the position',
                    'rounding_remainder_cents' => $remainderCents,
                    'excluded_replacement_days' => $gross['excluded_days'],
                    'pending_replacements_not_deducted' => $pendingReplacements,
                    'unpaid_leave_pending_not_deducted' => $pendingLeaves,
                    'unpaid_leave_rule' => 'approved unpaid leave, calendar days inside the assignment dates: salary in effect that day / days in month, rounded once per leave',
                    'adjustments_applied' => $pending->map(fn ($a) => ['id' => $a->id, 'kind' => $a->kind, 'amount_cents' => $a->amount_cents, 'reason' => $a->reason])->all(),
                ],
            ];
        }

        if ($withRecovery) {
            $this->applyOverpaymentRecovery($period, $items, $warnings);
        }

        if ($employeeIds === null) {
            $this->warnAboutOrphans($period, $items, $warnings);
        }
        $this->warnAboutUnownedInputs($period, $first, $last, $warnings);
        $warnings = array_values(array_unique($warnings));

        $collection = new Collection($items);

        return [
            'items' => $items,
            'warnings' => $warnings,
            'totals' => [
                'item_count' => count($items),
                'gross_cents' => (int) $collection->sum('gross_cents'),
                'replacement_deduction_cents' => (int) $collection->sum('replacement_deduction_cents'),
                'other_deduction_cents' => (int) $collection->sum('other_deduction_cents'),
                'adjustment_cents' => (int) $collection->sum('adjustment_cents'),
                'net_cents' => (int) $collection->sum('net_cents'),
            ],
        ];
    }

    /**
     * Caps the Temporary-cover deductions of one line so they never take more than the line has left (ReplacementDeductionCap). A capped deduction keeps the
     * cover's full cost in its detail ('amount_cents', the Temporary's payable amount) and records what was calculated, deducted and not deducted.
     *
     * @param  list<array<string, mixed>>  $deductions
     */
    private function capReplacementDeductions(array &$deductions, int $grossCents, int $otherCents, int $adjustmentCents): void
    {
        $indexes = array_keys(array_filter($deductions, fn (array $d): bool => $d['kind'] === PayrollItemDeduction::KIND_REPLACEMENT));

        if ($indexes === []) {
            return;
        }

        $applied = ReplacementDeductionCap::allocate(
            ReplacementDeductionCap::available($grossCents, $otherCents, $adjustmentCents),
            array_map(fn (int $i): int => (int) $deductions[$i]['amount_cents'], $indexes),
        );

        foreach ($indexes as $position => $index) {
            $cost = (int) $deductions[$index]['amount_cents'];
            $take = $applied[$position];

            if ($take === $cost) {
                continue;
            }

            $deductions[$index]['amount_cents'] = $take;
            $deductions[$index]['detail']['calculated_cost_cents'] = $cost;
            $deductions[$index]['detail']['deducted_cents'] = $take;
            $deductions[$index]['detail']['not_deducted_cents'] = $cost - $take;
            $deductions[$index]['detail']['capped_at_zero_net'] = true;
            $deductions[$index]['description'] .= ' - capped at what the salary has left: '.PayrollMoney::format($take).' deducted of '.PayrollMoney::format($cost).' (the net is never below 0)';
        }
    }

    /**
     * Calendar ranges of an assignment that a Replacement holds instead: the Permanent is not paid for them, so one position-day is
     * never paid to two people even if the dates were entered overlapping.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function replacementRanges(EmployeeWorkAssignment $assignment): array
    {
        if ($this->inputs?->hasAssignment($assignment->id)) {
            return $this->inputs->replacementRanges($assignment->id);
        }

        return [...EmployeeWorkAssignment::query()
            ->where('replaces_assignment_id', $assignment->id)
            ->where('assignment_type', AssignmentType::Replacement->value)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->get()
            ->map(fn (EmployeeWorkAssignment $successor): array => [$successor->start_date->toDateString(), $successor->end_date?->toDateString()])
            ->all(), ...AbsenceCycles::excludedRanges($assignment)];
    }

    /**
     * @param  list<array{0: string, 1: ?string}>  $ranges  inclusive date ranges, an open end is null
     */
    private static function withinRanges(string $iso, array $ranges): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($iso >= $from && ($to === null || $iso <= $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Days of this month on which the holder of $assignment is away and no Replacement works the position. Nobody is paid for them (the holder is
     * away, the Replacement is not there), so they are reported - also when a Replacement left and the absence is still open.
     *
     * @param  list<string>  $warnings
     */
    private function warnAboutUncoveredAbsence(EmployeeWorkAssignment $assignment, \DateTimeImmutable $first, \DateTimeImmutable $last, string $period, array &$warnings): void
    {
        if ($this->inputs?->hasAssignment($assignment->id)) {
            $windows = $this->inputs->absenceWindowsOf($assignment->id);
            $covers = $this->inputs->coverRangesOf($assignment->id);
        } else {
            $windows = AbsenceCycles::excludedRanges($assignment);
            $covers = $windows === [] ? [] : EmployeeWorkAssignment::query()
                ->where('replaces_assignment_id', $assignment->id)
                ->where('assignment_type', AssignmentType::Replacement->value)
                ->where('status', '!=', WorkAssignmentStatus::Cancelled)
                ->get()
                ->map(fn (EmployeeWorkAssignment $successor): array => [$successor->start_date->toDateString(), $successor->end_date?->toDateString()])
                ->all();
        }

        if ($windows === []) {
            return;
        }

        $start = $assignment->start_date->toDateString();
        $end = $assignment->end_date?->toDateString();
        $uncovered = [];

        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $iso = $day->format('Y-m-d');

            if ($iso < $start || ($end !== null && $iso > $end)) {
                continue;
            }

            if (self::withinRanges($iso, $windows) && ! self::withinRanges($iso, $covers)) {
                $uncovered[] = $iso;
            }
        }

        if ($uncovered !== []) {
            $warnings[] = 'Assignment #'.$assignment->id.': its holder is away and no Replacement works the position on '.count($uncovered).' day(s) of '.$period.' (first '.$uncovered[0].', last '.end($uncovered).'); nobody is paid for those days.';
        }
    }

    /**
     * Rounding rule (owner decision): lines that share one position are rounded so that they add up exactly to the whole position's
     * amount. Every line is rounded half-up to the cent EXCEPT the last one of the position (latest start date, then highest id),
     * which takes whatever cent remainder is left. Deterministic: the order is fixed by the assignment dates, never random.
     *
     * @param  Collection<int, EmployeeWorkAssignment>  $assignments  the assignments being calculated
     * @return array<int, array{gross_cents: int}> assignment id => reconciled gross, only for the last line of a position that needed one
     */
    private function reconciledChainGross(string $period, Collection $assignments): array
    {
        [, $daysInMonth] = ProrationCalculator::month($period);
        $result = [];
        $seen = [];

        foreach ($assignments as $assignment) {
            if (isset($seen[$assignment->id])) {
                continue;
            }

            $chain = $this->chainOf($assignment);

            foreach ($chain as $member) {
                $seen[$member->id] = true;
            }

            if ($chain->count() < 2) {
                continue;
            }

            $lines = [];

            foreach ($chain->sortBy([['start_date', 'asc'], ['id', 'asc']]) as $member) {
                $member->loadMissing('employee');

                if ($member->employee === null || $member->employee->trashed() || ($member->employee->employment_type === 'temporary' && ! EmploymentTypeChange::wasHeldAsPermanent($member->employee, $member))) {
                    continue;
                }

                try {
                    $gross = $this->proration->monthlyGross($period, new SalaryTimeline($member->monthlySalaryPeriods()), $member->start_date->toDateString(), $member->end_date?->toDateString(), $this->replacementRanges($member));
                } catch (\RuntimeException) {
                    continue;
                }

                if ($gross['eligible_days'] > 0) {
                    $lines[$member->id] = $gross;
                }
            }

            if (count($lines) < 2) {
                continue;
            }

            $target = PayrollMoney::roundDiv((int) array_sum(array_column($lines, 'numerator')), $daysInMonth);
            $ids = array_keys($lines);
            $lastId = end($ids);
            $running = 0;

            foreach ($lines as $id => $gross) {
                if ($id === $lastId) {
                    $result[$id] = ['gross_cents' => $target - $running];
                } else {
                    $running += $gross['gross_cents'];
                }
            }
        }

        return $result;
    }

    /**
     * Every non-cancelled assignment linked to this one through replaces_assignment_id, in either direction (the position's chain).
     *
     * @return Collection<int, EmployeeWorkAssignment>
     */
    private function chainOf(EmployeeWorkAssignment $assignment): Collection
    {
        // Known to be alone (nobody replaces it, it replaces nobody): the walk would only confirm that, one query per assignment.
        if ($this->inputs?->isInNoChain($assignment)) {
            return new Collection([$assignment->id => $assignment]);
        }

        return AssignmentChain::members($assignment);
    }

    /** Salary periods of an assignment: from the rows loaded with the run, or (not batched) from its own query. */
    private function salaryPeriodsOf(EmployeeWorkAssignment $assignment): array
    {
        return $this->inputs !== null && $assignment->relationLoaded('salaryHistories')
            ? $assignment->monthlySalaryPeriodsFrom($assignment->salaryHistories)
            : $assignment->monthlySalaryPeriods();
    }

    /**
     * Overpayment recovery (owner rule): an overpayment found in an earlier, already closed month is repaid from the employee's
     * later salaries. Each month deducts the LESSER of the balance still owed and the employee's payable salary that month (never
     * more, never below zero); what cannot be taken carries forward. The balance owed in a month is the original overpayment minus
     * what earlier months recovered, so recalculating or retrying can never deduct twice. A month without payable salary deducts
     * nothing. The closed month itself is never touched.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $warnings
     */
    private function applyOverpaymentRecovery(string $period, array &$items, array &$warnings): void
    {
        if ($items === []) {
            return;
        }

        $corrections = PayrollCorrection::query()
            ->where('kind', PayrollCorrection::KIND_OVERPAYMENT)
            ->where('status', '!=', PayrollCorrection::STATUS_SUPERSEDED)
            ->whereIn('employee_id', array_values(array_unique(array_column($items, 'employee_id'))))
            ->where('period', '<', $period)
            ->orderBy('id')
            ->get();

        foreach ($corrections->groupBy('employee_id') as $employeeId => $list) {
            $lineIndexes = [];
            $available = [];

            foreach ($items as $index => $item) {
                if ($item['employee_id'] === $employeeId) {
                    $lineIndexes[] = $index;
                    $available[$index] = max(0, (int) $item['net_cents']);
                }
            }

            foreach ($list as $correction) {
                // An overpayment is recovered from the company it was overpaid by, and only from that company's lines this month.
                $owner = $this->ownerKey($correction->client_company_id, (int) $employeeId);

                if ($owner === null) {
                    $warnings[] = "Overpayment correction #{$correction->id} names no company and the employee works for several: it was NOT recovered from any of them. Assign it to its company first.";

                    continue;
                }

                $myIndexes = array_values(array_filter($lineIndexes, fn (int $i): bool => ($this->lineKeys[$items[$i]['work_assignment_id']] ?? null) === $owner));

                if ($myIndexes === []) {
                    continue; // that company has no line this month: the balance waits for a month it has one (never taken from another company)
                }

                $recoveredEarlier = (int) PayrollRecoveryDeduction::query()
                    ->where('payroll_correction_id', $correction->id)->where('period', '<', $period)->sum('amount_cents');
                // Repayments recorded outside payroll (a person paying part of the debt back) also reduce what payroll may still take.
                $repaid = $correction->transactionCents(PayrollCorrectionTransaction::TYPE_DEBT_REPAYMENT);
                // A Super Admin write-off forgives part of the debt: payroll must never deduct what was written off.
                $writtenOff = $correction->transactionCents(PayrollCorrectionTransaction::TYPE_DEBT_WRITE_OFF);
                $balance = (int) $correction->difference_cents - $recoveredEarlier - $repaid - $writtenOff;
                $take = min($balance, (int) array_sum(array_intersect_key($available, array_flip($myIndexes))));

                if ($balance <= 0) {
                    continue;
                }

                // Leaving = every line of this employee this month has an end date (no open assignment).
                $leaving = count(array_filter($myIndexes, fn (int $i): bool => $items[$i]['calc_snapshot']['employment_window']['to'] !== null)) === count($myIndexes);
                $remaining = max(0, $take);

                foreach ($myIndexes as $index) {
                    $part = min($remaining, $available[$index]);

                    if ($part <= 0) {
                        continue;
                    }

                    $items[$index]['deductions'][] = [
                        'kind' => PayrollItemDeduction::KIND_OVERPAYMENT_RECOVERY,
                        'source_type' => 'payroll_correction_a'.$items[$index]['work_assignment_id'],
                        'source_id' => $correction->id,
                        'amount_cents' => $part,
                        'description' => "Recovery of overpayment from {$correction->period} (correction #{$correction->id}): ".PayrollMoney::format($take).' of '.PayrollMoney::format($balance).' still owed this month; '.PayrollMoney::format($balance - $take).' remains',
                        'detail' => [
                            'correction_id' => $correction->id,
                            'source_period' => $correction->period,
                            'original_overpayment_cents' => (int) $correction->difference_cents,
                            'owed_before_cents' => $balance,
                            'deducted_this_month_cents' => $take,
                            'owed_after_cents' => $balance - $take,
                        ],
                    ];
                    $items[$index]['other_deduction_cents'] += $part;
                    $items[$index]['net_cents'] -= $part;
                    $available[$index] -= $part;
                    $remaining -= $part;
                }

                // A leaving employee's final salary is taken up to what is available; whatever is left stays an outstanding debt.
                $left = $balance - max(0, $take);

                if ($leaving && $left > 0) {
                    $name = $items[$myIndexes[0]]['employee_number'];
                    $warnings[] = "Employee {$name} has no open assignment: the final salary of {$period} was used up to what is available, and ".PayrollMoney::format($left)." USD of the overpayment from {$correction->period} (correction #{$correction->id}) stays an outstanding debt to be managed on the corrections ledger.";
                }
            }
        }
    }

    /**
     * Final-pay marker for a Permanent employee whose assignment ended inside
     * this month: paid for every calendar day up to and including the last
     * day (weekends included), over the month's real number of days.
     *
     * @param  array<string, mixed>  $gross
     * @return array<string, mixed>|null
     */
    private function finalPay(EmployeeWorkAssignment $assignment, Employee $employee, \DateTimeImmutable $first, \DateTimeImmutable $last, array $gross): ?array
    {
        $end = $assignment->end_date;

        if ($end === null || $assignment->status === WorkAssignmentStatus::Cancelled || $end->gt($last) || $end->lt($first)) {
            return null;
        }

        return [
            'last_paid_day' => $end->toDateString(),
            'paid_calendar_days' => $gross['eligible_days'],
            'days_in_month' => $gross['days_in_month'],
            'rule' => 'salary x calendar days up to and including the last paid day / calendar days in the month; weekends are paid days',
            'employee_status' => $employee->status->value,
        ];
    }

    /**
     * Permanent succession check for this month: a successor starting on or
     * before the predecessor's last paid day pays two people for the same day
     * (reported loudly); days between the two are an uncovered gap (reported).
     *
     * @param  list<string>  $warnings
     * @return array<string, mixed>|null
     */
    private function succession(EmployeeWorkAssignment $assignment, string $period, \DateTimeImmutable $first, \DateTimeImmutable $last, array &$warnings): ?array
    {
        $result = null;

        $predecessor = match (true) {
            $assignment->replaces_assignment_id === null => null,
            (bool) $this->inputs?->hasAssignment($assignment->id) => $this->inputs->predecessor($assignment),
            default => EmployeeWorkAssignment::query()->find($assignment->replaces_assignment_id),
        };

        // A Replacement that took over while the holder was away (replacement cycles) is not an overlap: the days it holds are inside the holder's absence
        // window, which payroll does not pay to the holder. Whether the holder's assignment is still open or was ended meanwhile does not change that.
        $takesOverAnAbsence = $predecessor !== null
            && $assignment->assignment_type === AssignmentType::Replacement
            && ($this->inputs?->hasAssignment($predecessor->id) || $this->inputs?->hasAssignment($assignment->id)
                ? $this->inputs->isAwayOn($predecessor->id, $assignment->start_date->toDateString())
                : AbsenceCycles::isAwayOn($predecessor, $assignment->start_date));

        if ($predecessor !== null && $predecessor->status !== WorkAssignmentStatus::Cancelled && $takesOverAnAbsence) {
            $lastWorked = $predecessor->end_date;
            $result = ['replaces_assignment_id' => $predecessor->id, 'overlap' => false, 'covers_absence' => true];

            // The holder was ended while away and the Replacement is still working: say so, so the open position is not forgotten.
            if ($lastWorked !== null && $assignment->status === WorkAssignmentStatus::Active && ($assignment->end_date === null || $assignment->end_date->gt($lastWorked))) {
                $warnings[] = "Replacement #{$assignment->id} still holds a position whose Permanent assignment (#{$predecessor->id}) ended on {$lastWorked->toDateString()}. It keeps being paid on that assignment's salary; decide who holds the position (Replace Employee, or make the Replacement Permanent).";
                $result['permanent_holder_ended_on'] = $lastWorked->toDateString();
            }
        } elseif ($predecessor !== null && $predecessor->status !== WorkAssignmentStatus::Cancelled) {
            if ($predecessor->end_date === null || $assignment->start_date->lte($predecessor->end_date)) {
                $overlap = $predecessor->end_date === null
                    ? 'the assignment it replaces has no end date'
                    : 'it starts on '.$assignment->start_date->toDateString().' but the assignment it replaces is paid through '.$predecessor->end_date->toDateString();
                $warnings[] = $assignment->assignment_type === AssignmentType::Replacement
                    ? "Assignment #{$assignment->id} (Replacement) takes over assignment #{$predecessor->id}, but {$overlap}. The days the Replacement holds were NOT paid to the replaced assignment, so nobody is paid twice; fix the dates so the history is accurate."
                    : "DOUBLE PAY RISK: assignment #{$assignment->id} takes over assignment #{$predecessor->id}, but {$overlap}. Fix the dates before finalizing {$period}.";
                $result = ['replaces_assignment_id' => $predecessor->id, 'overlap' => true];
            } else {
                $gap = PositionSuccession::gapDays($predecessor->end_date, $assignment->start_date);
                $inMonth = $assignment->start_date->gte($first) && $assignment->start_date->lte($last);

                if ($gap > 0 && $inMonth) {
                    $warnings[] = "Assignment #{$assignment->id} starts {$gap} day(s) after the assignment it replaces (#{$predecessor->id}) ended; those {$gap} day(s) are uncovered and unpaid to anyone.";
                }

                $result = ['replaces_assignment_id' => $predecessor->id, 'overlap' => false, 'gap_days' => $gap, 'predecessor_last_paid_day' => $predecessor->end_date->toDateString()];
            }
        }

        $successor = $this->inputs?->hasAssignment($assignment->id)
            ? $this->inputs->successor($assignment->id)
            : EmployeeWorkAssignment::query()
                ->where('replaces_assignment_id', $assignment->id)
                ->where('status', '!=', WorkAssignmentStatus::Cancelled)
                ->orderBy('start_date')
                ->first();

        if ($successor !== null) {
            $result = ($result ?? []) + ['successor_assignment_id' => $successor->id, 'successor_start_date' => $successor->start_date->toDateString()];
        }

        return $result;
    }

    /**
     * Temporary-cover days that are NOT charged to the covered employee
     * (they were not payable that day) are reported, never silently dropped.
     *
     * @param  array<string, mixed>  $cost
     * @param  list<string>  $warnings
     */
    private function warnAboutNonChargeableDays(EmployeeWorkAssignment $assignment, TemporaryReplacement $replacement, array $cost, array &$warnings): void
    {
        $after = $cost['not_chargeable']['outside_assignment'] ?? [];
        $leave = $cost['not_chargeable']['on_unpaid_leave'] ?? [];

        if ($after !== []) {
            $warnings[] = 'Temporary replacement #'.$replacement->id.': '.count($after).' covered day(s) ('.implode(', ', $after)."), fall outside assignment #{$assignment->id}'s paid dates, so they were NOT charged to the employee.";
        }

        if ($leave !== []) {
            $warnings[] = 'Temporary replacement #'.$replacement->id.': '.count($leave).' covered day(s) ('.implode(', ', $leave).') were already unpaid leave for the covered employee, so they were NOT charged again.';
        }
    }

    /**
     * Covered Temporary-cover days whose covered employee has no payroll line this
     * month would never be deducted; say so instead of dropping them silently.
     *
     * @param  list<string>  $warnings
     */
    private function warnAboutUnpayableReplacementDays(EmployeeWorkAssignment $assignment, \DateTimeImmutable $first, \DateTimeImmutable $last, array &$warnings): void
    {
        $days = $this->coveredDaysOf(TemporaryReplacement::query()->where('work_assignment_id', $assignment->id)->get(), $first->format('Y-m'));

        if ($days > 0) {
            $warnings[] = "{$days} covered Temporary replacement day(s) for assignment #{$assignment->id} could NOT be deducted because that assignment has no payroll line this month.";
        }
    }

    /**
     * Calendar days of the month that the given covers declare (the days they declare between their start and actual end).
     *
     * @param  iterable<TemporaryReplacement>  $covers
     */
    private function coveredDaysOf(iterable $covers, string $period): int
    {
        $end = CarbonImmutable::parse("{$period}-01")->endOfMonth()->startOfDay();
        $days = 0;

        foreach ($covers as $cover) {
            // The days the cover declares in this month: the covered assignment has no line, so none of them can be deducted.
            $days += count(array_filter(CoveragePeriod::declaredDates($cover, $end), fn (string $iso): bool => str_starts_with($iso, $period.'-')));
        }

        return $days;
    }

    /**
     * Money that targets someone with no payroll line this month (adjustments,
     * penalties, advances) is reported rather than lost.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $warnings
     */
    /**
     * Inputs that could not be charged to a company, said plainly instead of being guessed: a record with no company when the employee works for
     * several, or one that names a company where the employee has no payroll line this month. They are charged nowhere until HR assigns them.
     *
     * @param  list<string>  $warnings
     */
    private function warnAboutUnownedInputs(string $period, \DateTimeImmutable $first, \DateTimeImmutable $last, array &$warnings): void
    {
        foreach ($this->employeeKeys as $employeeId => $keys) {
            $kinds = [
                'Penalty' => $this->inputs?->hasEmployee($employeeId) ? $this->inputs->penalties($employeeId) : EmployeePenalty::query()->active()->where('employee_id', $employeeId)->where('payroll_period', $period)->get(),
                'Advance' => $this->inputs?->hasEmployee($employeeId) ? $this->inputs->advances($employeeId) : EmployeeAdvance::query()->active()->where('employee_id', $employeeId)->where('payroll_period', $period)->get(),
                'Unpaid leave' => $this->inputs?->hasEmployee($employeeId) ? $this->inputs->unpaidLeaves($employeeId) : EmployeeLeave::query()->where('employee_id', $employeeId)->where('leave_type', LeaveType::Unpaid)->whereDate('start_date', '<=', $last->format('Y-m-d'))->whereDate('end_date', '>=', $first->format('Y-m-d'))->get(),
                'Absence mark' => $this->inputs?->hasEmployee($employeeId) ? $this->inputs->absences($employeeId) : EmployeeAttendance::query()->where('employee_id', $employeeId)->where('status', AttendanceStatus::Absent)->whereDate('date', '>=', $first->format('Y-m-d'))->whereDate('date', '<=', $last->format('Y-m-d'))->get(),
            ];

            foreach ($kinds as $label => $records) {
                $ambiguous = [];
                $elsewhere = [];

                foreach ($records as $record) {
                    $owner = $this->ownerKey($record->client_company_id, (int) $employeeId);

                    if ($owner === null) {
                        $ambiguous[] = '#'.$record->id;
                    } elseif (! isset($keys[$owner])) {
                        $elsewhere[] = '#'.$record->id;
                    }
                }

                if ($ambiguous !== []) {
                    $warnings[] = "{$label} ".implode(', ', array_slice($ambiguous, 0, 5)).(count($ambiguous) > 5 ? ' and '.(count($ambiguous) - 5).' more' : '')." of employee #{$employeeId} names no company and the employee works for several: it was NOT charged to any of them. Assign it to its company first.";
                }

                if ($elsewhere !== []) {
                    $warnings[] = "{$label} ".implode(', ', array_slice($elsewhere, 0, 5)).(count($elsewhere) > 5 ? ' and '.(count($elsewhere) - 5).' more' : '')." of employee #{$employeeId} belongs to a company where the employee has no payroll line in {$period}: it was NOT charged.";
                }
            }
        }
    }

    private function warnAboutOrphans(string $period, array $items, array &$warnings): void
    {
        $assignmentIds = array_column($items, 'work_assignment_id');
        $employeeIds = array_column($items, 'employee_id');

        foreach (PayrollAdjustment::query()->where('applies_in_period', $period)->where('status', 'pending')->with('item')->get() as $adjustment) {
            if (! in_array($adjustment->item?->work_assignment_id, $assignmentIds, true)) {
                $warnings[] = "Payroll adjustment #{$adjustment->id} ({$adjustment->kind}, {$adjustment->amount_cents} cents) cannot apply in {$period}: its assignment has no payroll line this month.";
            }
        }

        // Covered days of Temporary covers whose covered assignment has no payroll line this month (the coverage period decides, not the attendance ledger).
        $stranded = TemporaryReplacement::query()
            ->whereNotIn('work_assignment_id', $assignmentIds === [] ? [0] : $assignmentIds)
            ->get()
            ->groupBy('work_assignment_id')
            ->map(fn ($covers): int => $this->coveredDaysOf($covers, $period))
            ->filter(fn (int $days): bool => $days > 0);

        foreach ($stranded as $assignmentId => $days) {
            $warnings[] = "{$days} covered Temporary replacement day(s) for assignment #{$assignmentId} fall in {$period}, but that assignment has no payroll line this month (for example it ended earlier), so nothing was charged to the employee.";
        }

        foreach (EmployeePenalty::query()->active()->where('payroll_period', $period)->whereNotIn('employee_id', $employeeIds)->get() as $penalty) {
            $warnings[] = "Penalty #{$penalty->id} for {$period} was NOT deducted: the employee has no payroll line this month.";
        }

        foreach (EmployeeAdvance::query()->active()->where('payroll_period', $period)->whereNotIn('employee_id', $employeeIds)->get() as $advance) {
            $warnings[] = "Advance #{$advance->id} for {$period} was NOT deducted: the employee has no payroll line this month.";
        }
    }

    /**
     * @param  list<array<string, mixed>>  $deductions
     * @param  list<string>  $warnings
     */
    private function addPenaltiesAndAdvances(int $employeeId, string $period, string $lineKey, array &$deductions, array &$warnings): void
    {
        // Registered for this month OR an earlier one, with something still owed after what finalized payrolls already took (DeductionBalances).
        if ($this->inputs?->hasEmployee($employeeId)) {
            $penalties = $this->inputs->owedPenalties($employeeId);
            $advances = $this->inputs->owedAdvances($employeeId);
        } else {
            $owed = DeductionBalances::owedFor([$employeeId], $period);
            $penalties = $owed['penalties'][$employeeId] ?? new Collection;
            $advances = $owed['advances'][$employeeId] ?? new Collection;
        }

        foreach ($penalties as $penalty) {
            // Another company's penalty (or one whose company is ambiguous) is not charged on this company's line.
            if ($this->ownerKey($penalty->client_company_id, $employeeId) !== $lineKey) {
                continue;
            }

            if ($penalty->currency !== PayrollMoney::CURRENCY) {
                $warnings[] = "Penalty #{$penalty->id} is in {$penalty->currency}, not USD; it was not deducted.";

                continue;
            }

            $carried = $penalty->payroll_period !== $period;

            $deductions[] = [
                'kind' => PayrollItemDeduction::KIND_PENALTY, 'source_type' => DeductionBalances::TYPE_PENALTY, 'source_id' => $penalty->id,
                'amount_cents' => (int) $penalty->owed_cents,
                'description' => 'Penalty: '.$penalty->reason.($carried ? " (carried from {$penalty->payroll_period})" : ''),
                'detail' => [
                    'penalty_date' => $penalty->penalty_date?->toDateString(),
                    'registered_period' => $penalty->payroll_period,
                    'carried_in' => $carried,
                    'registered_cents' => $penalty->amountCents(),
                    'previously_deducted_cents' => (int) $penalty->deducted_cents,
                    'waived_cents' => (int) $penalty->waived_cents,
                    'owed_cents' => (int) $penalty->owed_cents,
                ],
            ];
        }

        foreach ($advances as $advance) {
            if ($this->ownerKey($advance->client_company_id, $employeeId) !== $lineKey) {
                continue;
            }

            if ($advance->currency !== PayrollMoney::CURRENCY) {
                $warnings[] = "Advance #{$advance->id} is in {$advance->currency}, not USD; it was not deducted.";

                continue;
            }

            $carried = $advance->payroll_period !== $period;

            $deductions[] = [
                'kind' => PayrollItemDeduction::KIND_ADVANCE, 'source_type' => DeductionBalances::TYPE_ADVANCE, 'source_id' => $advance->id,
                'amount_cents' => (int) $advance->owed_cents,
                'description' => 'Salary advance: '.$advance->reason.($carried ? " (carried from {$advance->payroll_period})" : ''),
                'detail' => [
                    'advance_date' => $advance->advance_date?->toDateString(),
                    'registered_period' => $advance->payroll_period,
                    'carried_in' => $carried,
                    'registered_cents' => $advance->amountCents(),
                    'previously_deducted_cents' => (int) $advance->deducted_cents,
                    'waived_cents' => (int) $advance->waived_cents,
                    'owed_cents' => (int) $advance->owed_cents,
                ],
            ];
        }
    }

    /**
     * Release 1B (owner decisions P-1 / A-1 / Q6 / Q7): penalties and advances never take more than the line has left. Unpaid leave and absence reduce the
     * salary first (they are what was not earned); then the pending adjustments count; then the penalties are deducted (older first), then the advances,
     * each up to the net of $0. What could not be deducted stays owed - it is NOT lost: the stored line says how much was owed, how much was deducted and
     * how much carries on (DeductionBalances derives the balance from the finalized lines), and the Generate warnings say it in words.
     *
     * @param  list<array<string, mixed>>  $deductions
     * @param  list<string>  $warnings
     */
    private function capOwedDeductions(array &$deductions, int $grossCents, int $adjustmentCents, string $employeeNumber, array &$warnings): void
    {
        $owedKinds = [PayrollItemDeduction::KIND_PENALTY, PayrollItemDeduction::KIND_ADVANCE];
        $owedIndexes = array_keys(array_filter($deductions, fn (array $d): bool => in_array($d['kind'], $owedKinds, true)));

        if ($owedIndexes === []) {
            return;
        }

        $earnedAway = (int) collect($deductions)->reject(fn (array $d): bool => in_array($d['kind'], [...$owedKinds, PayrollItemDeduction::KIND_REPLACEMENT], true))->sum('amount_cents');
        $available = max(0, $grossCents + $adjustmentCents - $earnedAway);

        // Penalties first, then advances; inside a kind the older registration first (they were added in that order).
        usort($owedIndexes, fn (int $a, int $b): int => [$deductions[$a]['kind'] === PayrollItemDeduction::KIND_PENALTY ? 0 : 1, $a] <=> [$deductions[$b]['kind'] === PayrollItemDeduction::KIND_PENALTY ? 0 : 1, $b]);

        foreach ($owedIndexes as $index) {
            $owed = (int) $deductions[$index]['amount_cents'];
            $take = min($owed, $available);
            $available -= $take;

            $deductions[$index]['detail']['owed_cents'] = $owed;
            $deductions[$index]['detail']['deducted_cents'] = $take;
            $deductions[$index]['detail']['not_deducted_cents'] = $owed - $take;
            $deductions[$index]['amount_cents'] = $take;

            if ($take < $owed) {
                $deductions[$index]['detail']['capped_at_zero_net'] = true;
                $deductions[$index]['detail']['carries_to_next_month'] = true;
                $deductions[$index]['description'] .= ' - only '.PayrollMoney::format($take).' of '.PayrollMoney::format($owed).' deducted (the net is never below 0); '.PayrollMoney::format($owed - $take).' stays owed and is deducted in the next months';
                $label = $deductions[$index]['kind'] === PayrollItemDeduction::KIND_PENALTY ? 'Penalty' : 'Advance';
                $warnings[] = "{$label} #{$deductions[$index]['source_id']} of {$employeeNumber}: ".PayrollMoney::format($owed).' owed, '.PayrollMoney::format($take).' deducted (the net is never below 0); '.PayrollMoney::format($owed - $take).' stays owed and carries to the next month.';
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $deductions
     */
    /**
     * Approved UNPAID leave reduces pay for the calendar days it covers inside
     * this assignment's dates and this month: each such day costs the salary in
     * effect that day / days in the month, rounded once per leave. A day covered
     * by two leave records is counted once. Pending leave is listed (and
     * reported) but not deducted; rejected leave is ignored.
     *
     * @param  list<array<string, mixed>>  $deductions
     * @param  list<array<string, mixed>>  $pending
     * @param  list<string>  $warnings
     * @return list<string> the dates already deducted as unpaid leave
     */
    private function addUnpaidLeave(EmployeeWorkAssignment $assignment, SalaryTimeline $salary, \DateTimeImmutable $first, \DateTimeImmutable $last, int $daysInMonth, array &$deductions, array &$pending, array &$warnings, array $heldElsewhere = []): array
    {
        $windowStart = $assignment->start_date->gt($first) ? CarbonImmutable::parse($assignment->start_date) : CarbonImmutable::parse($first);
        $windowEnd = $assignment->end_date !== null && $assignment->end_date->lt($last) ? CarbonImmutable::parse($assignment->end_date) : CarbonImmutable::parse($last);
        $counted = [];

        $leaves = $this->inputs?->hasEmployee($assignment->employee_id)
            ? $this->inputs->unpaidLeaves($assignment->employee_id)
            : EmployeeLeave::query()
                ->where('employee_id', $assignment->employee_id)
                ->where('leave_type', LeaveType::Unpaid)
                ->whereDate('start_date', '<=', $last->format('Y-m-d'))
                ->whereDate('end_date', '>=', $first->format('Y-m-d'))
                ->orderBy('id')
                ->get();

        foreach ($leaves as $leave) {
            // Unpaid leave reduces the pay of the company it belongs to, and only that company's.
            if ($this->ownerKey($leave->client_company_id, $assignment->employee_id) !== ($this->lineKeys[$assignment->id] ?? null)) {
                continue;
            }

            $from = $leave->start_date->gt($windowStart) ? CarbonImmutable::parse($leave->start_date) : $windowStart;
            $to = $leave->end_date->lt($windowEnd) ? CarbonImmutable::parse($leave->end_date) : $windowEnd;

            if ($to->lt($from) || $leave->approval_status === 'rejected') {
                continue;
            }

            if ($leave->approval_status !== 'approved') {
                $pending[] = ['leave_id' => $leave->id, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'approval_status' => $leave->approval_status];
                $warnings[] = "Unpaid leave #{$leave->id} ({$leave->start_date->toDateString()} to {$leave->end_date->toDateString()}) is awaiting approval and was NOT deducted from this month.";

                continue;
            }

            $numerator = 0;
            $days = [];

            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $iso = $day->toDateString();

                // A day this assignment is not paid for anyway (a Replacement holds it, or its holder is away) cannot cost it more.
                if (self::withinRanges($iso, $heldElsewhere)) {
                    continue;
                }

                $monthly = isset($counted[$iso]) ? null : $salary->monthlyCentsOn(new \DateTimeImmutable($iso));

                if ($monthly === null) {
                    continue;
                }

                $counted[$iso] = true;
                $numerator += $monthly;
                $days[] = $iso;
            }

            if ($days === []) {
                continue;
            }

            $deductions[] = [
                'kind' => PayrollItemDeduction::KIND_UNPAID_LEAVE,
                'source_type' => 'employee_leave_a'.$assignment->id,
                'source_id' => $leave->id,
                'amount_cents' => PayrollMoney::roundDiv($numerator, $daysInMonth),
                'description' => 'Approved unpaid leave: '.count($days).' day(s) ('.$days[0].($days[0] === end($days) ? '' : ' to '.end($days)).')',
                'detail' => ['leave_id' => $leave->id, 'days' => count($days), 'dates' => $days, 'days_in_month' => $daysInMonth, 'approved_at' => $leave->decided_at?->toIso8601String(), 'approved_by_id' => $leave->decided_by],
            ];
        }

        $warnings = array_values(array_unique($warnings));

        return array_keys($counted);
    }

    /**
     * @param  list<array<string, mixed>>  $deductions
     * @param  list<string>  $skipDates  days already deducted as unpaid leave (never deducted twice)
     */
    private function addAbsence(EmployeeWorkAssignment $assignment, SalaryTimeline $salary, \DateTimeImmutable $first, \DateTimeImmutable $last, int $daysInMonth, array &$deductions, array $skipDates = [], array $coveredByReplacement = [], ?array &$charged = null, array $heldElsewhere = []): void
    {
        $absences = $this->inputs?->hasEmployee($assignment->employee_id)
            ? $this->inputs->absences($assignment->employee_id)
            : EmployeeAttendance::query()
                ->where('employee_id', $assignment->employee_id)
                ->where('status', AttendanceStatus::Absent)
                ->whereDate('date', '>=', $first->format('Y-m-d'))->whereDate('date', '<=', $last->format('Y-m-d'))
                ->get();

        $numerator = 0;
        $dates = [];
        $coveredSkipped = [];

        foreach ($absences as $absence) {
            // An absence is charged to the company it belongs to, and only that company's line.
            if ($this->ownerKey($absence->client_company_id, $assignment->employee_id) !== ($this->lineKeys[$assignment->id] ?? null)) {
                continue;
            }

            $iso = $absence->date->toDateString();

            // Not paid to this assignment on that day (held by a Replacement, or its holder is away): nothing to deduct from it.
            if (self::withinRanges($iso, $heldElsewhere)) {
                continue;
            }

            if (in_array($iso, $coveredByReplacement, true) && ! isset($charged[$iso])) {
                $coveredSkipped[] = $iso;

                continue;
            }

            // Only days inside this assignment can cost money (an absence after the last paid day, or before the start, does not).
            // An absent day is charged once per employee, to the first assignment whose dates contain it.
            if (in_array($iso, $skipDates, true) || isset($charged[$iso]) || $iso < $assignment->start_date->toDateString() || ($assignment->end_date !== null && $iso > $assignment->end_date->toDateString())) {
                continue;
            }

            $monthly = $salary->monthlyCentsOn(new \DateTimeImmutable($absence->date->toDateString()));

            if ($monthly !== null) {
                $numerator += $monthly;
                $dates[] = $iso;
                $charged[$iso] = true;
            }
        }

        if ($numerator === 0) {
            return;
        }

        $deductions[] = [
            'kind' => PayrollItemDeduction::KIND_ABSENCE, 'source_type' => 'attendance_month_a'.$assignment->id, 'source_id' => $assignment->employee_id,
            'amount_cents' => PayrollMoney::roundDiv($numerator, $daysInMonth), 'description' => count($dates).' absent day(s) at the daily rate',
            'detail' => ['absent_dates' => $dates, 'not_deducted_covered_by_replacement' => $coveredSkipped],
        ];
    }
}
