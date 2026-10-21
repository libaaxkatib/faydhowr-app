<?php

namespace App\Support\Hr\Payroll;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;

/**
 * Builds the monthly "who do we pay, how much, to which number" list for one payroll month.
 *
 * It has NO formula of its own and writes nothing: every amount is a line from
 * PayrollPeriodView (the stored lines of an approved/finalized run, otherwise the same
 * PayrollRunCalculator the run uses, flagged as an estimate). The payment amount is that line's
 * NET payable, so proration, deductions and adjustments are never bypassed.
 *
 * One row per EMPLOYEE for the month. An employee with two assignments in the month has two payroll
 * lines; their amounts are added into one row so nobody is listed or counted twice. Company totals
 * keep each line under its own company, so company totals still add up to the grand total.
 *
 * The payment/account number follows PaymentNumber::resolve (owner rules): the Employee Number / Account Number - imported from the
 * Excel sources or typed by HR - is used when there is one (even if it differs from the phone); otherwise the employee's Phone Number
 * is used directly; with neither, the number is reported as missing. Every row names the employee together with the number and says
 * which of the two it is. Nothing is ever written back. An optional company filter produces the list
 * of one company only (only that company's lines are counted, so its total is that company's total).
 */
class MonthlyPaymentReportBuilder
{
    public const OFFICE_LABEL = 'Fayadhowr Office';

    public const CATEGORY_OFFICE = 'Office (internal)';

    public const CATEGORY_UNASSIGNED = 'No company';

    public const FLAG_NO_PAYMENT_NUMBER = 'Missing payment number';

    public const FLAG_REGISTER_MISMATCH = 'Imported payment number differs from the registration phone: review';

    public const FLAG_NO_SALARY = 'Missing salary';

    public const FLAG_NO_COMPANY = 'Missing company assignment';

    public const FLAG_MULTIPLE = 'More than one assignment this month (amounts added together)';

    public function __construct(private readonly PayrollPeriodView $periodView = new PayrollPeriodView) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $period, ?int $clientCompanyId = null): array
    {
        $view = $this->periodView->forPeriod($period);
        $lines = collect($view['lines']);
        [$monthStart, $monthEnd] = $this->monthBounds($period);

        $paymentStatusByAssignment = [];
        $voidedAssignmentIds = [];

        if ($view['source'] === PayrollPeriodView::SOURCE_STORED && $view['run_id'] !== null) {
            $items = PayrollItem::query()->where('payroll_run_id', $view['run_id'])->get(['work_assignment_id', 'payment_status', 'voided_at']);
            $voidedAssignmentIds = $items->whereNotNull('voided_at')->pluck('work_assignment_id')->all();
            $paymentStatusByAssignment = $items->whereNull('voided_at')->pluck('payment_status', 'work_assignment_id')->all();
        }

        $lines = $lines->reject(fn (array $line): bool => in_array($line['work_assignment_id'], $voidedAssignmentIds, true))->values();

        $assignments = EmployeeWorkAssignment::query()
            ->with(['clientCompany', 'workLocation.clientCompany'])
            ->whereIn('id', $lines->pluck('work_assignment_id')->all() ?: [0])
            ->get()
            ->keyBy('id');

        // One company only: keep the lines whose assignment belongs to that company (an employee who works for two companies appears
        // only with the line of the selected one, so the company total is exactly that company's).
        if ($clientCompanyId !== null) {
            $lines = $lines->filter(function (array $line) use ($assignments, $clientCompanyId): bool {
                $assignment = $assignments->get($line['work_assignment_id']);
                $company = $assignment?->clientCompany ?? $assignment?->workLocation?->clientCompany;

                return $company !== null && (int) $company->id === $clientCompanyId;
            })->values();
        }

        $employees = Employee::query()->whereIn('id', $lines->pluck('employee_id')->unique()->all() ?: [0])->get()->keyBy('id');

        $rows = [];
        $companyTotals = [];

        foreach ($lines as $line) {
            $employee = $employees->get($line['employee_id']);
            $assignment = $assignments->get($line['work_assignment_id']);
            [$companyName, $categoryLabel] = $this->companyOf($assignment);
            $salaryCents = $this->monthlySalaryCents($assignment, $monthStart, $monthEnd);
            $deductions = (int) $line['replacement_deduction_cents'] + (int) $line['other_deduction_cents'];
            $key = (int) $line['employee_id'];

            $row = $rows[$key] ?? [
                'employee_id' => $key,
                'employee_number' => $employee?->employee_number ?? $line['employee_number'],
                'employee_name' => $employee?->full_name ?? $line['employee_name'],
                'companies' => [],
                'categories' => [],
                'monthly_salary_cents' => null,
                'salary_missing' => false,
                'eligible_days' => [],
                'days_in_month' => (int) $line['days_in_month'],
                'gross_cents' => 0,
                'deductions_cents' => 0,
                'adjustment_cents' => 0,
                'net_cents' => 0,
                'line_count' => 0,
                'payment_statuses' => [],
                'company_missing' => false,
                'payment' => $employee !== null ? PaymentNumber::resolve($employee) : ['number' => null, 'label' => PaymentNumber::LABEL_MISSING, 'from_phone' => false, 'needs_review' => false],
            ];

            $row['companies'][$companyName] = true;
            $row['categories'][$categoryLabel] = true;
            $row['monthly_salary_cents'] = ($row['monthly_salary_cents'] ?? 0) + ($salaryCents ?? 0);
            $row['salary_missing'] = $row['salary_missing'] || $salaryCents === null || $salaryCents <= 0 || (int) $line['gross_cents'] <= 0;
            $row['eligible_days'][] = (int) $line['eligible_days'];
            $row['gross_cents'] += (int) $line['gross_cents'];
            $row['deductions_cents'] += $deductions;
            $row['adjustment_cents'] += (int) $line['adjustment_cents'];
            $row['net_cents'] += (int) $line['net_cents'];
            $row['line_count']++;
            $row['payment_statuses'][] = $paymentStatusByAssignment[$line['work_assignment_id']] ?? null;
            $row['company_missing'] = $row['company_missing'] || $companyName === self::CATEGORY_UNASSIGNED;
            $rows[$key] = $row;

            $companyTotals[$companyName] ??= ['company' => $companyName, 'category' => $categoryLabel, 'employee_ids' => [], 'net_cents' => 0];
            $companyTotals[$companyName]['employee_ids'][$key] = true;
            $companyTotals[$companyName]['net_cents'] += (int) $line['net_cents'];
        }

        $rows = array_map(fn (array $row): array => $this->finishRow($row, $view), array_values($rows));
        usort($rows, fn (array $a, array $b): int => [$a['company'], $a['employee_name'], $a['employee_number']] <=> [$b['company'], $b['employee_name'], $b['employee_number']]);

        // A company list is about that company's people only; the active-but-unassigned list belongs to the all-company report.
        $notInPayroll = $clientCompanyId === null ? $this->employeesNotInPayroll($period, $monthStart, $monthEnd, array_column($rows, 'employee_id')) : [];

        return [
            'period' => $period,
            'generated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i').' UTC',
            'source' => $view['source'],
            'is_estimate' => $view['is_estimate'],
            'run_status' => $view['run_status'],
            'rows' => $rows,
            'not_in_payroll' => $notInPayroll,
            'warnings' => $view['warnings'],
            'summary' => [...$this->summary($rows, $companyTotals, $notInPayroll, $view), 'company_filter' => $clientCompanyId !== null],
        ];
    }

    /**
     * @return array{0: string, 1: string} [company or guri name, category label]
     */
    private function companyOf(?EmployeeWorkAssignment $assignment): array
    {
        if ($assignment === null) {
            return [self::CATEGORY_UNASSIGNED, self::CATEGORY_UNASSIGNED];
        }

        $company = $assignment->clientCompany ?? $assignment->workLocation?->clientCompany;

        if ($company !== null) {
            return [$company->name, $company->category?->label() ?? 'Company'];
        }

        if ($assignment->workLocation?->location_type?->value === 'office') {
            return [self::OFFICE_LABEL, self::CATEGORY_OFFICE];
        }

        return [self::CATEGORY_UNASSIGNED, self::CATEGORY_UNASSIGNED];
    }

    /** The monthly salary in force on the last paid day of the month (what the employee is paid per month). */
    private function monthlySalaryCents(?EmployeeWorkAssignment $assignment, \DateTimeImmutable $monthStart, \DateTimeImmutable $monthEnd): ?int
    {
        if ($assignment === null) {
            return null;
        }

        try {
            $timeline = new SalaryTimeline($assignment->monthlySalaryPeriods());
        } catch (\RuntimeException|\InvalidArgumentException) {
            return null;
        }

        $day = $monthEnd;
        if ($assignment->end_date !== null && $assignment->end_date->lt($monthEnd)) {
            $day = new \DateTimeImmutable($assignment->end_date->toDateString());
        }
        if ($day < $monthStart) {
            $day = $monthStart;
        }

        return $timeline->monthlyCentsOn($day);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private function finishRow(array $row, array $view): array
    {
        $companies = array_keys($row['companies']);
        sort($companies);
        $categories = array_keys($row['categories']);
        sort($categories);
        $flags = [];

        if ($row['payment']['number'] === null) {
            $flags[] = self::FLAG_NO_PAYMENT_NUMBER;
        }
        if ($row['payment']['needs_review']) {
            $flags[] = self::FLAG_REGISTER_MISMATCH;
        }
        if ($row['salary_missing']) {
            $flags[] = self::FLAG_NO_SALARY;
        }
        if ($row['company_missing']) {
            $flags[] = self::FLAG_NO_COMPANY;
        }
        if ($row['line_count'] > 1) {
            $flags[] = self::FLAG_MULTIPLE;
        }

        return [
            'employee_id' => $row['employee_id'],
            'employee_number' => $row['employee_number'],
            'employee_name' => $row['employee_name'],
            'company' => implode('; ', $companies),
            'category' => implode('; ', $categories),
            'monthly_salary_cents' => $row['salary_missing'] ? null : $row['monthly_salary_cents'],
            'eligible_days' => $row['line_count'] > 1 ? implode(' + ', $row['eligible_days']) : $row['eligible_days'][0],
            'days_in_month' => $row['days_in_month'],
            'gross_cents' => $row['gross_cents'],
            'deductions_cents' => $row['deductions_cents'],
            'adjustment_cents' => $row['adjustment_cents'],
            'net_cents' => $row['net_cents'],
            'payment_account' => $row['payment']['number'],
            'payment_account_source' => $row['payment']['label'],
            'payment_from_phone' => $row['payment']['from_phone'],
            'payment_status' => $this->paymentStatus($row['payment_statuses'], $view),
            'flags' => $flags,
        ];
    }

    /**
     * Payment status is only RECORDED once a payroll run is finalized and a line is marked paid. For anything
     * else it says so plainly instead of showing "unpaid", which would be a claim nobody recorded.
     *
     * @param  list<?string>  $statuses
     * @param  array<string, mixed>  $view
     */
    private function paymentStatus(array $statuses, array $view): string
    {
        if ($view['source'] !== PayrollPeriodView::SOURCE_STORED || $view['run_status'] !== PayrollRun::STATUS_FINALIZED) {
            return 'Not recorded (payroll not finalized)';
        }

        $paid = count(array_filter($statuses, fn (?string $s): bool => $s === 'paid'));

        return match (true) {
            $paid === count($statuses) => 'Paid',
            $paid === 0 => 'Not paid',
            default => 'Partly paid',
        };
    }

    /**
     * Active Permanent employees who get no payment this month, with the reason. Never part of the totals.
     *
     * @param  list<int>  $paidEmployeeIds
     * @return list<array<string, mixed>>
     */
    private function employeesNotInPayroll(string $period, \DateTimeImmutable $monthStart, \DateTimeImmutable $monthEnd, array $paidEmployeeIds): array
    {
        $employees = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('employment_type')->orWhere('employment_type', '!=', 'temporary'))
            ->when($paidEmployeeIds !== [], fn ($q) => $q->whereNotIn('id', $paidEmployeeIds))
            ->orderBy('full_name')
            ->orderBy('id')
            ->get();

        $overlapping = EmployeeWorkAssignment::query()
            ->where('status', '!=', 'cancelled')
            ->whereIn('employee_id', $employees->pluck('id')->all() ?: [0])
            ->whereDate('start_date', '<=', $monthEnd->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $monthStart->format('Y-m-d')))
            ->pluck('employee_id')
            ->unique()
            ->flip();

        return $employees->map(fn (Employee $employee): array => [
            'employee_number' => $employee->employee_number,
            'employee_name' => $employee->full_name,
            'status' => $employee->status->value,
            'reason' => $overlapping->has($employee->id)
                ? 'Has an assignment this month but no payable amount was calculated (check the salary and the assignment dates)'
                : self::FLAG_NO_COMPANY.': no assignment covers '.$period,
            'flag' => $overlapping->has($employee->id) ? self::FLAG_NO_SALARY : self::FLAG_NO_COMPANY,
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $companyTotals
     * @param  list<array<string, mixed>>  $notInPayroll
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private function summary(array $rows, array $companyTotals, array $notInPayroll, array $view): array
    {
        $byCompany = array_values(array_map(fn (array $c): array => [
            'company' => $c['company'],
            'category' => $c['category'],
            'employee_count' => count($c['employee_ids']),
            'net_cents' => $c['net_cents'],
        ], $companyTotals));
        usort($byCompany, fn (array $a, array $b): int => [$a['category'], $a['company']] <=> [$b['category'], $b['company']]);

        $byCategory = [];
        foreach ($companyTotals as $company) {
            $byCategory[$company['category']] ??= ['category' => $company['category'], 'employee_ids' => [], 'net_cents' => 0, 'company_count' => 0];
            $byCategory[$company['category']]['employee_ids'] += $company['employee_ids'];
            $byCategory[$company['category']]['net_cents'] += $company['net_cents'];
            $byCategory[$company['category']]['company_count']++;
        }
        $byCategory = array_values(array_map(fn (array $c): array => [
            'category' => $c['category'],
            'company_count' => $c['company_count'],
            'employee_count' => count($c['employee_ids']),
            'net_cents' => $c['net_cents'],
        ], $byCategory));
        usort($byCategory, fn (array $a, array $b): int => $a['category'] <=> $b['category']);

        $count = fn (string $flag): int => count(array_filter($rows, fn (array $r): bool => in_array($flag, $r['flags'], true)));

        return [
            'employee_count' => count($rows),
            'total_net_cents' => array_sum(array_column($rows, 'net_cents')),
            'total_gross_cents' => array_sum(array_column($rows, 'gross_cents')),
            'total_deductions_cents' => array_sum(array_column($rows, 'deductions_cents')),
            'total_adjustment_cents' => array_sum(array_column($rows, 'adjustment_cents')),
            'missing_payment_number' => $count(self::FLAG_NO_PAYMENT_NUMBER),
            'phone_number_used' => count(array_filter($rows, fn (array $r): bool => $r['payment_from_phone'])),
            'register_mismatch' => $count(self::FLAG_REGISTER_MISMATCH),
            'missing_salary' => $count(self::FLAG_NO_SALARY),
            'missing_company' => $count(self::FLAG_NO_COMPANY),
            'not_in_payroll' => count($notInPayroll),
            'by_category' => $byCategory,
            'by_company' => $byCompany,
            'source_label' => $view['source'] === PayrollPeriodView::SOURCE_STORED
                ? 'Payroll run '.($view['run_status'] ?? '').' (stored amounts)'
                : 'ESTIMATE - calculated live, no approved payroll run exists for this month',
        ];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function monthBounds(string $period): array
    {
        [$first, $days] = ProrationCalculator::month($period);

        return [$first, $first->modify('+'.($days - 1).' days')];
    }
}
