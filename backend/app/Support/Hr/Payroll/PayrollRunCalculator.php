<?php

namespace App\Support\Hr\Payroll;

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
use App\Models\PayrollItemDeduction;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementWorkday;
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
    public function calculate(string $period, ?array $employeeIds = null): array
    {
        [$first, $daysInMonth] = ProrationCalculator::month($period);
        $last = $first->modify('+'.($daysInMonth - 1).' days');
        $warnings = [];
        $items = [];
        $firstItemForEmployee = [];
        $absenceCharged = [];

        $assignments = EmployeeWorkAssignment::query()
            ->with(['employee', 'workLocation', 'clientCompany', 'workLocation.clientCompany'])
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds === [] ? [0] : $employeeIds))
            ->whereDate('start_date', '<=', $last->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $first->format('Y-m-d')))
            ->orderBy('id')
            ->get();

        foreach ($assignments as $assignment) {
            $employee = $assignment->employee;

            if ($employee === null || $employee->trashed()) {
                continue;
            }

            if ($employee->employment_type === 'temporary') {
                $warnings[] = "Assignment #{$assignment->id} belongs to a Temporary employee ({$employee->employee_number}); Temporary pay is the replacement cost deducted from the covered Permanent employee, so no payroll line was created.";

                continue;
            }

            try {
                $salary = new SalaryTimeline($assignment->monthlySalaryPeriods());
                $gross = $this->proration->monthlyGross(
                    $period,
                    $salary,
                    $assignment->start_date->toDateString(),
                    $assignment->end_date?->toDateString(),
                );
            } catch (\RuntimeException $e) {
                $warnings[] = "Assignment #{$assignment->id} ({$employee->employee_number}): {$e->getMessage()}";
                $this->warnAboutUnpayableReplacementDays($assignment, $first, $last, $warnings);

                continue;
            }

            if ($employee->status->value !== 'active' && $assignment->status === WorkAssignmentStatus::Active && $gross['eligible_days'] > 0) {
                $warnings[] = "Employee {$employee->employee_number} is {$employee->status->value} but still has an open assignment (#{$assignment->id}) in {$period}; they were included. End the assignment if employment ended.";
            }

            if ($gross['eligible_days'] === 0) {
                continue;
            }

            $deductions = [];
            $pendingReplacements = [];
            $coveredDates = [];

            foreach (TemporaryReplacement::query()->with('replacementEmployee')->where('work_assignment_id', $assignment->id)->get() as $replacement) {
                $cost = $this->replacementCosts->forPeriod($replacement, $period);

                $this->warnAboutNonChargeableDays($assignment, $replacement, $cost, $warnings);

                if ($cost['verified_days'] === 0 && $cost['amount_cents'] === 0) {
                    continue;
                }

                $detail = [
                    'replacement_id' => $replacement->id,
                    'temporary_employee_id' => $replacement->replacement_employee_id,
                    'temporary_employee_number' => $replacement->replacementEmployee?->employee_number,
                    'temporary_employee_name' => $replacement->replacementEmployee?->full_name,
                    'start_date' => $replacement->start_date->toDateString(),
                    'end_date' => $replacement->end_date?->toDateString(),
                    'verified_days' => $cost['verified_days'],
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
                    'description' => "Temporary cover by {$detail['temporary_employee_name']}: {$cost['verified_days']} verified day(s)",
                    'detail' => $detail,
                ];
            }

            if (! isset($firstItemForEmployee[$employee->id])) {
                $firstItemForEmployee[$employee->id] = true;
                $this->addPenaltiesAndAdvances($employee->id, $period, $deductions, $warnings);
            }

            $pendingLeaves = [];
            $leaveDates = $this->addUnpaidLeave($assignment, $salary, $first, $last, $daysInMonth, $deductions, $pendingLeaves, $warnings);

            $this->addAbsence($assignment, $salary, $first, $last, $daysInMonth, $deductions, $leaveDates, $coveredDates, $absenceCharged[$employee->id]);

            $replacementCents = (int) collect($deductions)->where('kind', PayrollItemDeduction::KIND_REPLACEMENT)->sum('amount_cents');
            $otherCents = (int) collect($deductions)->where('kind', '!=', PayrollItemDeduction::KIND_REPLACEMENT)->sum('amount_cents');
            $pending = PayrollAdjustment::query()
                ->where('applies_in_period', $period)->where('status', 'pending')
                ->whereHas('item', fn ($q) => $q->where('work_assignment_id', $assignment->id))->get();
            $adjustmentCents = (int) $pending->sum('amount_cents');

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
                    'rounding' => 'exact fraction rounded half-up to the cent once per line',
                    'pending_replacements_not_deducted' => $pendingReplacements,
                    'unpaid_leave_pending_not_deducted' => $pendingLeaves,
                    'unpaid_leave_rule' => 'approved unpaid leave, calendar days inside the assignment dates: salary in effect that day / days in month, rounded once per leave',
                    'adjustments_applied' => $pending->map(fn ($a) => ['id' => $a->id, 'kind' => $a->kind, 'amount_cents' => $a->amount_cents, 'reason' => $a->reason])->all(),
                ],
            ];
        }

        if ($employeeIds === null) {
            $this->warnAboutOrphans($period, $items, $warnings);
        }
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

        $predecessor = $assignment->replaces_assignment_id !== null
            ? EmployeeWorkAssignment::query()->find($assignment->replaces_assignment_id)
            : null;

        if ($predecessor !== null && $predecessor->status !== WorkAssignmentStatus::Cancelled) {
            if ($predecessor->end_date === null || $assignment->start_date->lte($predecessor->end_date)) {
                $overlap = $predecessor->end_date === null
                    ? 'the assignment it replaces has no end date'
                    : 'it starts on '.$assignment->start_date->toDateString().' but the assignment it replaces is paid through '.$predecessor->end_date->toDateString();
                $warnings[] = "DOUBLE PAY RISK: assignment #{$assignment->id} takes over assignment #{$predecessor->id}, but {$overlap}. Fix the dates before approving {$period}.";
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

        $successor = EmployeeWorkAssignment::query()
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
     * Verified Temporary-cover days that are NOT charged to the covered employee
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
            $warnings[] = 'Temporary replacement #'.$replacement->id.': '.count($after).' verified day(s) ('.implode(', ', $after)."), fall outside assignment #{$assignment->id}'s paid dates, so they were NOT charged to the employee.";
        }

        if ($leave !== []) {
            $warnings[] = 'Temporary replacement #'.$replacement->id.': '.count($leave).' verified day(s) ('.implode(', ', $leave).') were already unpaid leave for the covered employee, so they were NOT charged again.';
        }
    }

    /**
     * Verified replacement days whose covered employee has no payroll line this
     * month would never be deducted; say so instead of dropping them silently.
     *
     * @param  list<string>  $warnings
     */
    private function warnAboutUnpayableReplacementDays(EmployeeWorkAssignment $assignment, \DateTimeImmutable $first, \DateTimeImmutable $last, array &$warnings): void
    {
        $days = TemporaryReplacementWorkday::query()
            ->where('work_assignment_id', $assignment->id)
            ->where('status', TemporaryReplacementWorkday::STATUS_VERIFIED)
            ->whereDate('work_date', '>=', $first->format('Y-m-d'))->whereDate('work_date', '<=', $last->format('Y-m-d'))
            ->count();

        if ($days > 0) {
            $warnings[] = "{$days} verified Temporary replacement day(s) for assignment #{$assignment->id} could NOT be deducted because that assignment has no payroll line this month.";
        }
    }

    /**
     * Money that targets someone with no payroll line this month (adjustments,
     * penalties, advances) is reported rather than lost.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $warnings
     */
    private function warnAboutOrphans(string $period, array $items, array &$warnings): void
    {
        $assignmentIds = array_column($items, 'work_assignment_id');
        $employeeIds = array_column($items, 'employee_id');

        foreach (PayrollAdjustment::query()->where('applies_in_period', $period)->where('status', 'pending')->with('item')->get() as $adjustment) {
            if (! in_array($adjustment->item?->work_assignment_id, $assignmentIds, true)) {
                $warnings[] = "Payroll adjustment #{$adjustment->id} ({$adjustment->kind}, {$adjustment->amount_cents} cents) cannot apply in {$period}: its assignment has no payroll line this month.";
            }
        }

        $stranded = TemporaryReplacementWorkday::query()
            ->where('status', TemporaryReplacementWorkday::STATUS_VERIFIED)
            ->whereDate('work_date', '>=', "{$period}-01")->whereDate('work_date', '<=', CarbonImmutable::parse("{$period}-01")->endOfMonth()->toDateString())
            ->whereNotIn('work_assignment_id', $assignmentIds === [] ? [0] : $assignmentIds)
            ->selectRaw('work_assignment_id, count(*) as days')
            ->groupBy('work_assignment_id')
            ->get();

        foreach ($stranded as $row) {
            $warnings[] = "{$row->days} verified Temporary replacement day(s) for assignment #{$row->work_assignment_id} fall in {$period}, but that assignment has no payroll line this month (for example it ended earlier), so nothing was charged to the employee.";
        }

        foreach (EmployeePenalty::query()->where('payroll_period', $period)->whereNotIn('employee_id', $employeeIds)->get() as $penalty) {
            $warnings[] = "Penalty #{$penalty->id} for {$period} was NOT deducted: the employee has no payroll line this month.";
        }

        foreach (EmployeeAdvance::query()->where('payroll_period', $period)->whereNotIn('employee_id', $employeeIds)->get() as $advance) {
            $warnings[] = "Advance #{$advance->id} for {$period} was NOT deducted: the employee has no payroll line this month.";
        }
    }

    /**
     * @param  list<array<string, mixed>>  $deductions
     * @param  list<string>  $warnings
     */
    private function addPenaltiesAndAdvances(int $employeeId, string $period, array &$deductions, array &$warnings): void
    {
        foreach (EmployeePenalty::query()->where('employee_id', $employeeId)->where('payroll_period', $period)->get() as $penalty) {
            if ($penalty->currency !== PayrollMoney::CURRENCY) {
                $warnings[] = "Penalty #{$penalty->id} is in {$penalty->currency}, not USD; it was not deducted.";

                continue;
            }

            $deductions[] = [
                'kind' => PayrollItemDeduction::KIND_PENALTY, 'source_type' => 'employee_penalty', 'source_id' => $penalty->id,
                'amount_cents' => PayrollMoney::toCents($penalty->deduction_amount), 'description' => 'Penalty: '.$penalty->reason,
                'detail' => ['penalty_date' => $penalty->penalty_date?->toDateString()],
            ];
        }

        foreach (EmployeeAdvance::query()->where('employee_id', $employeeId)->where('payroll_period', $period)->get() as $advance) {
            if ($advance->currency !== PayrollMoney::CURRENCY) {
                $warnings[] = "Advance #{$advance->id} is in {$advance->currency}, not USD; it was not deducted.";

                continue;
            }

            $deductions[] = [
                'kind' => PayrollItemDeduction::KIND_ADVANCE, 'source_type' => 'employee_advance', 'source_id' => $advance->id,
                'amount_cents' => PayrollMoney::toCents($advance->amount), 'description' => 'Salary advance: '.$advance->reason,
                'detail' => ['advance_date' => $advance->advance_date?->toDateString()],
            ];
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
    private function addUnpaidLeave(EmployeeWorkAssignment $assignment, SalaryTimeline $salary, \DateTimeImmutable $first, \DateTimeImmutable $last, int $daysInMonth, array &$deductions, array &$pending, array &$warnings): array
    {
        $windowStart = $assignment->start_date->gt($first) ? CarbonImmutable::parse($assignment->start_date) : CarbonImmutable::parse($first);
        $windowEnd = $assignment->end_date !== null && $assignment->end_date->lt($last) ? CarbonImmutable::parse($assignment->end_date) : CarbonImmutable::parse($last);
        $counted = [];

        $leaves = EmployeeLeave::query()
            ->where('employee_id', $assignment->employee_id)
            ->where('leave_type', LeaveType::Unpaid)
            ->whereDate('start_date', '<=', $last->format('Y-m-d'))
            ->whereDate('end_date', '>=', $first->format('Y-m-d'))
            ->orderBy('id')
            ->get();

        foreach ($leaves as $leave) {
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
    private function addAbsence(EmployeeWorkAssignment $assignment, SalaryTimeline $salary, \DateTimeImmutable $first, \DateTimeImmutable $last, int $daysInMonth, array &$deductions, array $skipDates = [], array $coveredByReplacement = [], ?array &$charged = null): void
    {
        $absences = EmployeeAttendance::query()
            ->where('employee_id', $assignment->employee_id)
            ->where('status', AttendanceStatus::Absent)
            ->whereDate('date', '>=', $first->format('Y-m-d'))->whereDate('date', '<=', $last->format('Y-m-d'))
            ->get();

        $numerator = 0;
        $dates = [];
        $coveredSkipped = [];

        foreach ($absences as $absence) {
            $iso = $absence->date->toDateString();

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
