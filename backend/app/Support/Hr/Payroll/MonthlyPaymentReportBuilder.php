<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AssignmentType;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;

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
 *
 * TEMPORARY WORKERS (docs/HR_PAYROLL_RULES.md, owner decision of 2026-10-27): a Temporary who covered a Permanent employee's position in the month is
 * listed on a row of their own, directly BENEATH the covered Permanent's row (the same adjacency the payroll run workbook uses), linked by the
 * recorded cover (temporary_replacements.work_assignment_id -> the covered assignment's employee) - never by name. The Temporary row has no payroll
 * line: its amount is the cover's entitlement for the month from TemporaryExpectedPayment (the one coverage-period calculation also used for the
 * deduction), and its balance is that entitlement accrued up to the end of the month minus the payments recorded up to then. Because the
 * Temporary is paid through the cover and the Permanent's line only has the deduction taken off, nobody is paid twice: Permanent net + Temporary
 * amount = the covered salary. Employee counts and the existing totals are the Permanent employees' only; the Temporary figures are added beside them.
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

    public const FLAG_COVER_NOT_APPROVED = 'Cover not approved: nothing is deducted from the Permanent employee yet';

    public const FLAG_DEDUCTION_DIFFERS = 'Deduction from the Permanent is smaller than this amount (days already unpaid leave)';

    public const FLAG_PERMANENT_NOT_LISTED = 'The covered Permanent employee has no payment line this month';

    public const FLAG_COVER_OVERLAP = 'Overlaps another cover recorded before the no-overlap rule: correct the dates';

    public const FLAG_COVERED_UNKNOWN = 'This Replacement does not record which Permanent employee it covers';

    public function __construct(
        private readonly PayrollPeriodView $periodView = new PayrollPeriodView,
        private readonly TemporaryExpectedPayment $temporaryPayments = new TemporaryExpectedPayment,
    ) {}

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

        // The payment state of a line is read where the month holds stored lines (approved / finalized companies, or a whole stored run).
        if ($view['run_id'] !== null && $lines->contains(fn (array $line): bool => ($line['line_source'] ?? null) === PayrollPeriodView::LINE_STORED)) {
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

        // What the workbook says about its figures follows the lines it INCLUDES: stored (approved / finalized companies), estimate (companies still
        // in draft or reviewed), or both. A list for one finalized company is therefore stored, never an estimate, even in a month that is partly draft.
        if ($lines->isNotEmpty()) {
            $stored = $lines->contains(fn (array $line): bool => ($line['line_source'] ?? null) === PayrollPeriodView::LINE_STORED);
            $estimate = $lines->contains(fn (array $line): bool => ($line['line_source'] ?? PayrollPeriodView::LINE_ESTIMATE) === PayrollPeriodView::LINE_ESTIMATE);
            $view['is_estimate'] = $estimate;
            $view['source'] = $stored && $estimate ? PayrollPeriodView::SOURCE_MIXED : ($stored ? PayrollPeriodView::SOURCE_STORED : PayrollPeriodView::SOURCE_ESTIMATE);
        }

        $employees = Employee::query()->whereIn('id', $lines->pluck('employee_id')->unique()->all() ?: [0])->get()->keyBy('id');

        $rows = [];
        $companyTotals = [];
        $replacementLines = [];

        foreach ($lines as $line) {
            $employee = $employees->get($line['employee_id']);
            $assignment = $assignments->get($line['work_assignment_id']);
            [$companyName, $categoryLabel] = $this->companyOf($assignment);

            // Company totals count every payroll line (Permanent and Replacement alike), so they still add up to the payroll total.
            $companyTotals[$companyName] ??= ['company' => $companyName, 'category' => $categoryLabel, 'employee_ids' => [], 'net_cents' => 0];
            $companyTotals[$companyName]['employee_ids'][(int) $line['employee_id']] = true;
            $companyTotals[$companyName]['net_cents'] += (int) $line['net_cents'];

            // A Replacement's line is paid to the Replacement, not to the Permanent they covered, and may belong to someone who also holds a Permanent
            // assignment: it is never merged into that person's Permanent row. It gets a row of its own (see replacementRows).
            if ($assignment?->assignment_type === AssignmentType::Replacement) {
                $replacementLines[] = $line;

                continue;
            }

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
                'recorded' => true,
                'paid_cents' => 0,
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
            // What has been paid is a recorded fact only when EVERY line of this person's row is in a finalized company (or run).
            $row['recorded'] = $row['recorded'] && (bool) ($line['payment_recorded'] ?? false);
            $row['payment_statuses'][] = $paymentStatusByAssignment[$line['work_assignment_id']] ?? null;
            // What has been paid of this line: only a line marked paid in a finalized run counts (see finishRow).
            $row['paid_cents'] += ($paymentStatusByAssignment[$line['work_assignment_id']] ?? null) === 'paid' ? (int) $line['net_cents'] : 0;
            $row['company_missing'] = $row['company_missing'] || $companyName === self::CATEGORY_UNASSIGNED;
            $rows[$key] = $row;
        }

        $rows = array_map(fn (array $row): array => $this->finishRow($row, $view), array_values($rows));
        usort($rows, fn (array $a, array $b): int => [$a['company'], $a['employee_name'], $a['employee_number']] <=> [$b['company'], $b['employee_name'], $b['employee_number']]);

        // Replacement workers are paid through rows of their own: they are in the payroll even when they hold no Permanent row.
        $replacementRows = $this->replacementRows($replacementLines, $assignments, $employees, $paymentStatusByAssignment, $view, $monthStart, $monthEnd, $rows);

        // A company list is about that company's people only; the active-but-unassigned list belongs to the all-company report.
        $notInPayroll = $clientCompanyId === null ? $this->employeesNotInPayroll($period, $monthStart, $monthEnd, array_column([...$rows, ...$replacementRows], 'employee_id')) : [];

        // Temporary workers: one row per cover with days in the month, placed beneath the covered Permanent. They are not payroll lines, so every
        // count and total above stays the Permanent employees' own; the Temporary figures are reported next to them.
        $temporaryRows = $this->temporaryRows($period, $monthStart, $monthEnd, $clientCompanyId, $rows);

        return [
            'period' => $period,
            'generated_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i').' UTC',
            'source' => $view['source'],
            'is_estimate' => $view['is_estimate'],
            'run_status' => $view['run_status'],
            'rows' => $this->withTemporaryRows($rows, [...$temporaryRows, ...$replacementRows]),
            'not_in_payroll' => $notInPayroll,
            'warnings' => $view['warnings'],
            'summary' => [
                ...$this->summary($rows, $replacementRows, $companyTotals, $notInPayroll, $view),
                ...$this->temporarySummary($temporaryRows, $replacementRows, $lines, $rows),
                'company_filter' => $clientCompanyId !== null,
            ],
        ];
    }

    /**
     * One row per Temporary cover that has days in the month. The relationship comes from the recorded cover itself (the covered assignment and its
     * employee), never from names. The amounts are the cover's entitlement (TemporaryExpectedPayment): nothing is recalculated here.
     *
     * @param  list<array<string, mixed>>  $permanentRows  the rows already built (their employee ids say which covered Permanents are listed)
     * @return list<array<string, mixed>>
     */
    private function temporaryRows(string $period, \DateTimeImmutable $monthStart, \DateTimeImmutable $monthEnd, ?int $clientCompanyId, array $permanentRows): array
    {
        $listed = array_flip(array_column($permanentRows, 'employee_id'));
        $through = CarbonImmutable::parse($monthEnd->format('Y-m-d'));

        $covers = TemporaryReplacement::query()
            ->with(['workAssignment.employee', 'workAssignment.clientCompany', 'workAssignment.workLocation.clientCompany', 'replacementEmployee'])
            ->whereDate('start_date', '<=', $monthEnd->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $monthStart->format('Y-m-d')))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($covers as $cover) {
            $position = $cover->workAssignment;
            $temporary = $cover->replacementEmployee;
            $permanent = $position?->employee;

            if ($position === null || $temporary === null || $permanent === null) {
                continue;
            }

            $company = $position->clientCompany ?? $position->workLocation?->clientCompany;

            if ($clientCompanyId !== null && ($company === null || (int) $company->id !== $clientCompanyId)) {
                continue;
            }

            try {
                $expected = $this->temporaryPayments->forReplacement($cover, $through);
            } catch (HttpResponseException) {
                $expected = null; // no covered salary: the amount is never guessed, the row says so
            }

            $month = $expected === null ? null : collect($expected['months'])->firstWhere('period', $period);

            if ($expected !== null && $month === null) {
                continue; // none of the cover's counted days falls in this month
            }

            $paid = PayrollMoney::toCents((string) TemporaryReplacementPayment::query()->where('temporary_replacement_id', $cover->id)->whereDate('payment_date', '<=', $monthEnd->format('Y-m-d'))->sum('amount'));
            $accrued = $expected === null ? null : array_sum(array_column($expected['months'], 'amount_cents'));
            $balance = $accrued === null ? null : $accrued - $paid;
            [$companyName, $categoryLabel] = $this->companyOf($position);
            $payment = PaymentNumber::resolve($temporary);
            $isListed = isset($listed[$permanent->id]);
            $flags = [];

            if ($payment['number'] === null) {
                $flags[] = self::FLAG_NO_PAYMENT_NUMBER;
            }
            if ($payment['needs_review']) {
                $flags[] = self::FLAG_REGISTER_MISMATCH;
            }
            if ($expected === null) {
                $flags[] = self::FLAG_NO_SALARY;
            }
            if ($expected !== null && $cover->approved_at === null) {
                $flags[] = self::FLAG_COVER_NOT_APPROVED;
            }
            if ($expected !== null && $cover->approved_at !== null && $month['deduction_cents'] !== $month['amount_cents']) {
                $flags[] = self::FLAG_DEDUCTION_DIFFERS;
            }
            if (! $isListed) {
                $flags[] = self::FLAG_PERMANENT_NOT_LISTED;
            }
            if ($expected !== null && collect($expected['warnings'])->contains('code', 'OVERLAPPING_COVER')) {
                $flags[] = self::FLAG_COVER_OVERLAP;
            }
            $segments = $month['segments'] ?? [];
            $lastSegment = $segments === [] ? null : $segments[array_key_last($segments)];

            $rows[] = [
                'row_type' => 'temporary',
                'type' => 'Temporary',
                'employee_id' => $temporary->id,
                'employee_number' => $temporary->employee_number,
                'employee_name' => $temporary->full_name,
                'cover_id' => $cover->id,
                'linked_employee_id' => $permanent->id,
                'linked_employee_number' => $permanent->employee_number,
                'linked_employee_name' => $permanent->full_name,
                'linked_listed' => $isListed,
                'company' => $companyName,
                'category' => $categoryLabel,
                // The salary the cover is calculated on is the covered Permanent's; it is not the Temporary's own salary, so the salary cell stays "n/a".
                'monthly_salary_cents' => null,
                'covered_salary_cents' => $lastSegment['monthly_cents'] ?? null,
                'eligible_days' => $month['days'] ?? 0,
                'days_in_month' => $month['days_in_month'] ?? (int) $monthStart->format('t'),
                'gross_cents' => $month['amount_cents'] ?? 0,
                'deductions_cents' => 0,
                'adjustment_cents' => 0,
                'net_cents' => $month['amount_cents'] ?? 0,
                'cover_from' => $expected['from'] ?? $cover->start_date->toDateString(),
                'cover_through' => $expected['through'] ?? $cover->end_date?->toDateString(),
                'cover_open_ended' => $cover->end_date === null,
                'planned_end_date' => $cover->planned_end_date?->toDateString(),
                'approved' => $cover->approved_at !== null,
                // What is actually taken off the Permanent's line for this cover: the calculated deduction counts only once the cover is approved.
                'permanent_deduction_cents' => $cover->approved_at !== null ? ($month['deduction_cents'] ?? 0) : 0,
                'accrued_cents' => $accrued,
                'paid_cents' => $paid,
                'balance_cents' => $balance,
                'payment_account' => $payment['number'],
                'payment_account_source' => $payment['label'],
                'payment_from_phone' => $payment['from_phone'],
                'payment_status' => $this->temporaryPaymentStatus($accrued, $paid),
                'flags' => $flags,
            ];
        }

        return $rows;
    }

    /**
     * One row per Replacement assignment that has a payroll line in the month (a Replacement takes over a position and is paid as a normal pay line at
     * the covered Permanent's salary, A8). The row names the Replacement, their own payment number, the Permanent whose position they hold (found from
     * the recorded `replaces_assignment_id`, never from names) and the dates of their assignment, and sits beneath that Permanent like a Temporary's.
     * The amount is the payroll line's net payable, untouched: nothing is recalculated here, so the Permanent's pay line (which already excludes the
     * days the Replacement holds) and this one never pay the same day twice.
     *
     * @param  list<array<string, mixed>>  $replacementLines  the payroll lines of Replacement assignments
     * @param  Collection<int, EmployeeWorkAssignment>  $assignments
     * @param  Collection<int, Employee>  $employees
     * @param  array<int, ?string>  $paymentStatusByAssignment
     * @param  array<string, mixed>  $view
     * @param  list<array<string, mixed>>  $permanentRows
     * @return list<array<string, mixed>>
     */
    private function replacementRows(array $replacementLines, Collection $assignments, Collection $employees, array $paymentStatusByAssignment, array $view, \DateTimeImmutable $monthStart, \DateTimeImmutable $monthEnd, array $permanentRows): array
    {
        $listed = array_flip(array_column($permanentRows, 'employee_id'));
        $rows = [];

        foreach ($replacementLines as $line) {
            $recorded = (bool) ($line['payment_recorded'] ?? false);
            $assignment = $assignments->get($line['work_assignment_id']);
            $employee = $employees->get($line['employee_id']);
            $covered = $assignment === null ? null : $this->coveredPermanent($assignment);
            $holder = $covered?->employee;
            [$companyName, $categoryLabel] = $this->companyOf($assignment);
            $payment = $employee !== null ? PaymentNumber::resolve($employee) : ['number' => null, 'label' => PaymentNumber::LABEL_MISSING, 'from_phone' => false, 'needs_review' => false];
            $salaryCents = $this->monthlySalaryCents($assignment, $monthStart, $monthEnd);
            $isListed = $holder !== null && isset($listed[$holder->id]);
            $net = (int) $line['net_cents'];
            $status = $paymentStatusByAssignment[$line['work_assignment_id']] ?? null;
            $paid = $recorded && $status === 'paid' ? $net : 0;
            $flags = [];

            if ($payment['number'] === null) {
                $flags[] = self::FLAG_NO_PAYMENT_NUMBER;
            }
            if ($payment['needs_review']) {
                $flags[] = self::FLAG_REGISTER_MISMATCH;
            }
            if ($salaryCents === null || $salaryCents <= 0 || (int) $line['gross_cents'] <= 0) {
                $flags[] = self::FLAG_NO_SALARY;
            }
            if ($companyName === self::CATEGORY_UNASSIGNED) {
                $flags[] = self::FLAG_NO_COMPANY;
            }
            if ($holder === null) {
                $flags[] = self::FLAG_COVERED_UNKNOWN;
            } elseif (! $isListed) {
                $flags[] = self::FLAG_PERMANENT_NOT_LISTED;
            }

            $rows[] = [
                'row_type' => 'replacement',
                'type' => 'Replacement',
                'employee_id' => (int) $line['employee_id'],
                'employee_number' => $employee?->employee_number ?? $line['employee_number'],
                'employee_name' => $employee?->full_name ?? $line['employee_name'],
                'assignment_id' => (int) $line['work_assignment_id'],
                'cover_id' => null,
                'linked_employee_id' => $holder?->id,
                'linked_employee_number' => $holder?->employee_number,
                'linked_employee_name' => $holder?->full_name,
                'linked_listed' => $isListed,
                'company' => $companyName,
                'category' => $categoryLabel,
                'monthly_salary_cents' => $salaryCents,
                'covered_salary_cents' => $salaryCents,
                'eligible_days' => (int) $line['eligible_days'],
                'days_in_month' => (int) $line['days_in_month'],
                'gross_cents' => (int) $line['gross_cents'],
                'deductions_cents' => (int) $line['replacement_deduction_cents'] + (int) $line['other_deduction_cents'],
                'adjustment_cents' => (int) $line['adjustment_cents'],
                'net_cents' => $net,
                'cover_from' => $assignment?->start_date->toDateString(),
                'cover_through' => $assignment?->end_date?->toDateString(),
                'cover_open_ended' => $assignment?->end_date === null,
                'planned_end_date' => null,
                'approved' => true,
                'permanent_deduction_cents' => 0,
                'accrued_cents' => $net,
                // As for a Permanent's line: what was paid is only a recorded fact once the run is finalized; before that it is "not recorded".
                'paid_cents' => $recorded ? $paid : null,
                'balance_cents' => $recorded ? $net - $paid : null,
                'payment_account' => $payment['number'],
                'payment_account_source' => $payment['label'],
                'payment_from_phone' => $payment['from_phone'],
                'payment_status' => $this->paymentStatus([$status], $recorded),
                'flags' => $flags,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['cover_from'], $a['assignment_id']] <=> [$b['cover_from'], $b['assignment_id']]);

        return $rows;
    }

    /**
     * The Permanent assignment a Replacement holds the position of: follow `replaces_assignment_id` back through earlier Replacements to the first
     * assignment that is not itself a Replacement. null when the Replacement records nobody (the row then says so).
     */
    private function coveredPermanent(EmployeeWorkAssignment $assignment): ?EmployeeWorkAssignment
    {
        $current = $assignment;

        for ($hops = 0; $hops < 10 && $current->replaces_assignment_id !== null; $hops++) {
            $parent = EmployeeWorkAssignment::query()->with('employee')->find($current->replaces_assignment_id);

            if ($parent === null) {
                return null;
            }

            if ($parent->assignment_type !== AssignmentType::Replacement) {
                return $parent;
            }

            $current = $parent;
        }

        return null;
    }

    /** Whether a Temporary has been paid what the cover has earned up to the end of the month. This one IS recorded (cover payments are their own records). */
    private function temporaryPaymentStatus(?int $accrued, int $paid): string
    {
        return match (true) {
            $accrued === null => 'Not calculated (no covered salary)',
            $paid > $accrued => 'Paid more than the amount earned',
            $paid === $accrued => 'Paid',
            $paid === 0 => 'Not paid',
            default => 'Partly paid',
        };
    }

    /**
     * Each Temporary row directly beneath its Permanent's row (several covers: oldest first). A cover whose Permanent has no row this month is kept,
     * flagged, after the listed employees - it is still money owed to the Temporary.
     *
     * @param  list<array<string, mixed>>  $permanentRows
     * @param  list<array<string, mixed>>  $temporaryRows
     * @return list<array<string, mixed>>
     */
    private function withTemporaryRows(array $permanentRows, array $temporaryRows): array
    {
        $byPermanent = [];

        foreach ($temporaryRows as $row) {
            $byPermanent[(int) $row['linked_employee_id']][] = $row;
        }

        // Several workers under one Permanent: in the order they took over (Temporary covers and Replacements together).
        foreach ($byPermanent as &$group) {
            usort($group, fn (array $a, array $b): int => [$a['cover_from'], $a['cover_id'] ?? $a['assignment_id'] ?? 0] <=> [$b['cover_from'], $b['cover_id'] ?? $b['assignment_id'] ?? 0]);
        }
        unset($group);

        $merged = [];

        foreach ($permanentRows as $row) {
            $merged[] = $row;

            foreach ($byPermanent[$row['employee_id']] ?? [] as $temporary) {
                $merged[] = $temporary;
            }

            unset($byPermanent[$row['employee_id']]);
        }

        $orphans = array_merge([], ...array_values($byPermanent));
        $orphanKey = fn (array $r): array => [$r['company'], (string) $r['linked_employee_name'], $r['cover_from'], $r['cover_id'] ?? $r['assignment_id'] ?? 0];
        usort($orphans, fn (array $a, array $b): int => $orphanKey($a) <=> $orphanKey($b));

        return [...$merged, ...$orphans];
    }

    /**
     * The Temporary figures beside the existing totals, and the reconciliation: what the covers earned in the month against what was taken off the
     * Permanent employees' lines for them (the two are the same calculation; they differ only by covers not yet approved or days already unpaid leave).
     *
     * @param  list<array<string, mixed>>  $temporaryRows
     * @param  list<array<string, mixed>>  $replacementRows
     * @param  Collection<int, array<string, mixed>>  $lines  the payroll lines of the listed employees
     * @param  list<array<string, mixed>>  $permanentRows
     * @return array<string, mixed>
     */
    private function temporarySummary(array $temporaryRows, array $replacementRows, Collection $lines, array $permanentRows): array
    {
        $earned = array_sum(array_column($temporaryRows, 'net_cents'));
        $replacementTotal = array_sum(array_column($replacementRows, 'net_cents'));
        $permanentTotal = array_sum(array_column($permanentRows, 'net_cents'));
        $coverDeductions = array_sum(array_column($temporaryRows, 'permanent_deduction_cents'));
        $lineDeductions = (int) $lines->sum('replacement_deduction_cents');
        $balances = array_filter(array_column($temporaryRows, 'balance_cents'), fn ($v): bool => $v !== null);

        return [
            'temporary_cover_count' => count($temporaryRows),
            'temporary_employee_count' => count(array_unique(array_column($temporaryRows, 'employee_id'))),
            'temporary_total_cents' => $earned,
            'temporary_paid_to_date_cents' => array_sum(array_column($temporaryRows, 'paid_cents')),
            'temporary_balance_cents' => array_sum($balances),
            'temporary_cover_deductions_cents' => $coverDeductions,
            'permanent_replacement_deductions_cents' => $lineDeductions,
            // The two deduction figures are the same calculation; they agree to the cent unless a cover or leave changed after an approved run was stored.
            'temporary_deductions_reconcile' => $coverDeductions === $lineDeductions,
            'temporary_not_deducted_cents' => $earned - $coverDeductions,
            // The three kinds of worker, kept apart: Permanent employees' own rows, Replacements (payroll lines of their own) and Temporary covers.
            'permanent_total_cents' => $permanentTotal,
            'replacement_count' => count($replacementRows),
            'replacement_employee_count' => count(array_unique(array_column($replacementRows, 'employee_id'))),
            'replacement_total_cents' => $replacementTotal,
            'temporary_replacement_total_cents' => $earned + $replacementTotal,
            // Permanent + Replacement + Temporary: every person's amount once. It equals total_net_cents (all payroll lines) + the Temporary covers.
            'total_with_temporary_cents' => $permanentTotal + $replacementTotal + $earned,
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

        // "Paid to date" is only a recorded fact once the line's company (or run) is finalized; before that it is not known, and is reported as such (null), never as 0.
        $recorded = (bool) $row['recorded'];

        return [
            'row_type' => 'permanent',
            'type' => 'Permanent',
            'paid_cents' => $recorded ? $row['paid_cents'] : null,
            'balance_cents' => $recorded ? $row['net_cents'] - $row['paid_cents'] : null,
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
            'payment_status' => $this->paymentStatus($row['payment_statuses'], $recorded),
            'flags' => $flags,
        ];
    }

    /**
     * Payment status is only RECORDED once a payroll run is finalized and a line is marked paid. For anything
     * else it says so plainly instead of showing "unpaid", which would be a claim nobody recorded.
     *
     * @param  list<?string>  $statuses
     * @param  bool  $recorded  every line of the row is in a finalized company (or run)
     */
    private function paymentStatus(array $statuses, bool $recorded): string
    {
        if (! $recorded) {
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
     * @param  list<array<string, mixed>>  $permanentRows
     * @param  list<array<string, mixed>>  $replacementRows
     * @param  array<string, array<string, mixed>>  $companyTotals
     * @param  list<array<string, mixed>>  $notInPayroll
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    private function summary(array $permanentRows, array $replacementRows, array $companyTotals, array $notInPayroll, array $view): array
    {
        // The payroll lines of the month: Permanent rows and Replacement rows. The employee count is the Permanent employees' own.
        $rows = [...$permanentRows, ...$replacementRows];

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
            'employee_count' => count($permanentRows),
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
            'source_label' => match ($view['source']) {
                PayrollPeriodView::SOURCE_STORED => 'Payroll run '.($view['run_status'] ?? '').' (stored amounts)',
                PayrollPeriodView::SOURCE_MIXED => 'MIXED - companies whose payroll is approved or finalized use their stored amounts; the others are an ESTIMATE calculated live (their payroll is not finalized yet)',
                default => 'ESTIMATE - calculated live, no approved payroll run exists for this month',
            },
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
