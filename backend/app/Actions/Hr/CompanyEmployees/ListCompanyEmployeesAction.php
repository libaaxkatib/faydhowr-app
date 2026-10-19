<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Enums\AttendanceStatus;
use App\Enums\WorkAssignmentStatus;
use App\Models\ClientCompany;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollPeriodView;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The company's employee list for one month, with a company summary. Nothing is invented:
 *
 *  - contracted salary  = the salary recorded on the assignment (the one in effect in that month);
 *  - payable days       = the calendar days of the month that fall inside the assignment's dates (the approved payroll rule:
 *                          calendar days, weekends included);
 *  - attendance         = the marks HR actually recorded for those days (present / late / absent). The system stores marks, not a
 *                          complete roll of worked days, so "worked days" is NOT claimed - only what was recorded is counted;
 *  - calculated pay     = the existing payroll engine's result for that month (the approved/finalized run when there is one,
 *                          otherwise a live estimate, labelled as such). Payroll is USD-only, so an assignment in another
 *                          currency gets no calculated pay rather than a wrong one;
 *  - actual payment     = the paid flag of the stored payroll item, if the month has a stored run.
 *
 * Totals are always per currency; different currencies are never added. Calculated pay and payment are returned only when the
 * caller may see payroll (hr.payroll.view); the contracted salary needs only hr.view, as everywhere else.
 */
class ListCompanyEmployeesAction
{
    public function __construct(private readonly PayrollPeriodView $payroll = new PayrollPeriodView) {}

    /**
     * @param  array{status?: string|null, position_id?: int|null, assignment_type?: string|null, search?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function handle(ClientCompany $company, string $period, array $filters, bool $withPayroll): array
    {
        $monthStart = CarbonImmutable::parse($period.'-01')->startOfDay();
        $monthEnd = $monthStart->endOfMonth()->startOfDay();
        $daysInMonth = (int) $monthStart->daysInMonth;

        $assignments = EmployeeWorkAssignment::query()
            ->with(['employee', 'position', 'salaryHistories', 'workLocation'])
            ->where('client_company_id', $company->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $monthStart->toDateString()))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['position_id']), fn ($q) => $q->where('position_id', (int) $filters['position_id']))
            ->when(! empty($filters['assignment_type']), fn ($q) => $q->where('assignment_type', $filters['assignment_type']))
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $needle = '%'.mb_strtolower(trim((string) $filters['search'])).'%';
                $q->whereHas('employee', fn ($e) => $e->whereRaw('lower(full_name) like ?', [$needle])->orWhereRaw('lower(employee_number) like ?', [$needle]));
            })
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $employeeIds = $assignments->pluck('employee_id')->unique()->values()->all();
        $attendance = $employeeIds === [] ? collect() : EmployeeAttendance::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->groupBy('employee_id');

        $others = $employeeIds === [] ? collect() : EmployeeWorkAssignment::query()
            ->with(['clientCompany', 'workLocation'])
            ->whereIn('employee_id', $employeeIds)
            ->where('client_company_id', '!=', $company->id)
            ->where('status', '!=', WorkAssignmentStatus::Cancelled)
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $monthStart->toDateString()))
            ->get()
            ->groupBy('employee_id');

        $payrollInfo = $withPayroll ? $this->payrollFor($period, $assignments) : null;

        $rows = $assignments->map(fn (EmployeeWorkAssignment $a): array => $this->row($a, $monthStart, $monthEnd, $daysInMonth, $attendance->get($a->employee_id, collect()), $payrollInfo, $others->get($a->employee_id, collect())));

        return [
            'period' => $period,
            'company' => ['id' => $company->id, 'name' => $company->name],
            'employees' => $rows->all(),
            'summary' => $this->summary($rows->all(), $payrollInfo, $withPayroll, $daysInMonth),
        ];
    }

    /** @param  Collection<int, EmployeeAttendance>  $marks */
    private function row(EmployeeWorkAssignment $a, CarbonImmutable $monthStart, CarbonImmutable $monthEnd, int $daysInMonth, $marks, ?array $payrollInfo, $otherAssignments): array
    {
        $from = $a->start_date->greaterThan($monthStart) ? CarbonImmutable::parse($a->start_date) : $monthStart;
        $to = $a->end_date !== null && $a->end_date->lessThan($monthEnd) ? CarbonImmutable::parse($a->end_date) : $monthEnd;
        $payableDays = $to->gte($from) ? (int) $from->diffInDays($to) + 1 : 0;

        $inWindow = $marks->filter(fn (EmployeeAttendance $m): bool => $m->date->toDateString() >= $from->toDateString() && $m->date->toDateString() <= $to->toDateString());
        $count = fn (AttendanceStatus $s): int => $inWindow->filter(fn (EmployeeAttendance $m): bool => $m->status === $s)->count();

        [$salary, $changedInPeriod] = $this->salaryFor($a, $monthStart, $to);

        $row = [
            'assignment_id' => $a->id,
            'employee_id' => $a->employee_id,
            'employee_number' => $a->employee?->employee_number,
            'full_name' => $a->employee?->full_name,
            'employee_status' => $a->employee?->status->value,
            'position_id' => $a->position_id,
            'position_name' => $a->position?->name,
            'work_location_name' => $a->workLocation?->name,
            'assignment_type' => $a->assignment_type?->value ?? 'permanent',
            'replaces_assignment_id' => $a->replaces_assignment_id,
            'start_date' => $a->start_date->toDateString(),
            'end_date' => $a->end_date?->toDateString(),
            'status' => $a->status->value,
            // The same employee's assignments at OTHER companies in this month (only when multiple-company assignments are on).
            'also_assigned_at' => $otherAssignments->map(fn (EmployeeWorkAssignment $o): array => ['assignment_id' => $o->id, 'company_id' => $o->client_company_id, 'company_name' => $o->clientCompany?->name ?? $o->workLocation?->name])->values()->all(),
            'salary' => $salary,
            'salary_changed_in_period' => $changedInPeriod,
            // Whether this assignment still holds the position on the month's last day. A replaced employee and their replacement
            // both appear in the month they overlap, but the position's agreed salary is counted once, for the one who holds it.
            'holds_position_at_month_end' => $a->end_date === null || $a->end_date->toDateString() >= $monthEnd->toDateString(),
            'payable_days' => $payableDays,
            'days_in_month' => $daysInMonth,
            'attendance' => [
                'recorded' => $inWindow->count(),
                'present' => $count(AttendanceStatus::Present),
                'late' => $count(AttendanceStatus::Late),
                'absent' => $count(AttendanceStatus::Absent),
            ],
        ];

        if ($payrollInfo !== null) {
            $row['payroll'] = $this->payrollRow($a, $payrollInfo);
        }

        return $row;
    }

    /** @return array{0: array{amount: string, currency: string, frequency: string}, 1: bool} */
    private function salaryFor(EmployeeWorkAssignment $a, CarbonImmutable $monthStart, CarbonImmutable $windowEnd): array
    {
        $asOf = $windowEnd->toDateString();
        $effective = $a->salaryHistories->filter(fn ($h): bool => $h->effective_from->toDateString() <= $asOf)->last();
        $changed = $a->salaryHistories->filter(fn ($h): bool => $h->effective_from->toDateString() > $monthStart->toDateString() && $h->effective_from->toDateString() <= $asOf)->isNotEmpty();

        return [[
            'amount' => (string) ($effective?->amount ?? $a->salary_amount),
            'currency' => (string) ($effective?->currency ?? $a->salary_currency),
            'frequency' => ($effective?->frequency ?? $a->salary_frequency)->value,
        ], $changed];
    }

    /** @return array<string, mixed> */
    private function payrollFor(string $period, $assignments): array
    {
        $usd = $assignments->filter(fn (EmployeeWorkAssignment $a): bool => $a->salary_currency === PayrollMoney::CURRENCY);

        if ($usd->isEmpty()) {
            return ['available' => true, 'lines' => [], 'source' => null, 'run_status' => null, 'paid' => [], 'warnings' => []];
        }

        try {
            $view = $this->payroll->forPeriod($period, $usd->pluck('employee_id')->unique()->values()->all());
        } catch (Throwable $e) {
            return ['available' => false, 'reason' => 'The payroll calculation could not be produced for this month: '.$e->getMessage(), 'lines' => [], 'source' => null, 'run_status' => null, 'paid' => [], 'warnings' => []];
        }

        $paid = [];
        if ($view['run_id'] !== null && ! $view['is_estimate']) {
            foreach (PayrollItem::query()->where('payroll_run_id', $view['run_id'])->whereIn('work_assignment_id', $usd->pluck('id'))->get() as $item) {
                $paid[(int) $item->work_assignment_id] = ['status' => $item->payment_status ?? 'unpaid', 'paid_at' => $item->paid_at?->toIso8601String()];
            }
        }

        return [
            'available' => true,
            'lines' => collect($view['lines'])->keyBy('work_assignment_id')->all(),
            'source' => $view['source'],
            'run_status' => $view['run_status'],
            'paid' => $paid,
            'warnings' => $view['warnings'],
        ];
    }

    /** @return array<string, mixed> */
    private function payrollRow(EmployeeWorkAssignment $a, array $info): array
    {
        if ($a->salary_currency !== PayrollMoney::CURRENCY) {
            return ['calculated' => null, 'unavailable_reason' => "Payroll supports USD only; this assignment's salary is in {$a->salary_currency}."];
        }

        if (! $info['available']) {
            return ['calculated' => null, 'unavailable_reason' => $info['reason']];
        }

        $line = $info['lines'][$a->id] ?? null;

        if ($line === null) {
            return ['calculated' => null, 'unavailable_reason' => 'No payroll line exists for this assignment in this month.'];
        }

        return [
            'calculated' => [
                'currency' => PayrollMoney::CURRENCY,
                'gross_cents' => (int) $line['gross_cents'],
                'deductions_cents' => (int) ($line['replacement_deduction_cents'] ?? 0) + (int) ($line['other_deduction_cents'] ?? 0),
                'adjustment_cents' => (int) ($line['adjustment_cents'] ?? 0),
                'net_cents' => (int) $line['net_cents'],
                'eligible_days' => (int) $line['eligible_days'],
                'source' => $info['source'],
                'is_estimate' => $info['source'] === PayrollPeriodView::SOURCE_ESTIMATE,
                'run_status' => $info['run_status'],
            ],
            'payment' => $info['paid'][$a->id] ?? null,
            'unavailable_reason' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows, ?array $payrollInfo, bool $withPayroll, int $daysInMonth): array
    {
        $contracted = [];
        $nonMonthly = 0;
        $calculated = [];
        $paid = [];
        $unavailable = 0;

        foreach ($rows as $row) {
            $currency = $row['salary']['currency'];

            // An assignment that ended inside the month is not in the agreed-salary total: its successor holds the position.
            if ($row['holds_position_at_month_end']) {
                if ($row['salary']['frequency'] === 'monthly') {
                    $contracted[$currency] = ($contracted[$currency] ?? 0) + PayrollMoney::toCents($row['salary']['amount']);
                } else {
                    $nonMonthly++;
                }
            }

            if (! $withPayroll) {
                continue;
            }

            $calc = $row['payroll']['calculated'] ?? null;
            if ($calc === null) {
                $unavailable++;

                continue;
            }

            $calculated[$calc['currency']]['gross_cents'] = ($calculated[$calc['currency']]['gross_cents'] ?? 0) + $calc['gross_cents'];
            $calculated[$calc['currency']]['net_cents'] = ($calculated[$calc['currency']]['net_cents'] ?? 0) + $calc['net_cents'];

            if (($row['payroll']['payment']['status'] ?? null) === 'paid') {
                $paid[$calc['currency']] = ($paid[$calc['currency']] ?? 0) + $calc['net_cents'];
            }
        }

        $statuses = array_count_values(array_column($rows, 'status'));
        $types = array_count_values(array_column($rows, 'assignment_type'));

        $summary = [
            'employee_count' => count($rows),
            'active_count' => $statuses['active'] ?? 0,
            'ended_count' => $statuses['ended'] ?? 0,
            'permanent_count' => $types['permanent'] ?? 0,
            'replacement_count' => $types['replacement'] ?? 0,
            'days_in_month' => $daysInMonth,
            'payable_days_total' => array_sum(array_column($rows, 'payable_days')),
            'attendance_recorded_total' => array_sum(array_map(fn (array $r): int => $r['attendance']['recorded'], $rows)),
            'absent_marks_total' => array_sum(array_map(fn (array $r): int => $r['attendance']['absent'], $rows)),
            // Agreed MONTHLY salaries of the assignments that still hold their position on the month's last day, per currency. They are
            // the agreed amounts, not what the month will pay (calculated pay is separate).
            'contracted_monthly_salary_cents_by_currency' => $contracted,
            'contracted_non_monthly_count' => $nonMonthly,
        ];

        if ($withPayroll) {
            $summary += [
                'calculated_pay_by_currency' => $calculated,
                'calculated_pay_unavailable_count' => $unavailable,
                'calculated_pay_source' => $payrollInfo['source'] ?? null,
                'calculated_pay_is_estimate' => ($payrollInfo['source'] ?? null) === PayrollPeriodView::SOURCE_ESTIMATE,
                'paid_net_cents_by_currency' => $paid,
                'payroll_warnings' => $payrollInfo['warnings'] ?? [],
            ];
        }

        return $summary;
    }
}
