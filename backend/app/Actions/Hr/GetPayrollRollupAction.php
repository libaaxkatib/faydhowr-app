<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollPeriodView;
use App\Support\Hr\Payroll\PayrollSummaryPresenter;
use Illuminate\Support\Collection;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: a management-level payroll rollup for
 * a period, optionally scoped to a company/department. It has no formula of
 * its own: every row is the same line the payroll run holds (PayrollPeriodView),
 * so a row here always equals the per-employee summary and the payroll run.
 * When the month has no approved/finalized run the rows are an ESTIMATE and the
 * meta says so.
 */
class GetPayrollRollupAction
{
    public function __construct(private readonly PayrollPeriodView $periodView = new PayrollPeriodView) {}

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function handle(string $payrollPeriod, array $filters = []): array
    {
        $view = $this->periodView->forPeriod($payrollPeriod);
        $lines = collect($view['lines']);

        $assignments = EmployeeWorkAssignment::query()
            ->with(['workLocation.clientCompany'])
            ->whereIn('id', $lines->pluck('work_assignment_id')->all() ?: [0])
            ->get()
            ->keyBy('id');
        $employees = Employee::query()->with('department')->whereIn('id', $lines->pluck('employee_id')->all() ?: [0])->get()->keyBy('id');

        $lines = $lines->filter(function (array $line) use ($filters, $assignments, $employees): bool {
            $assignment = $assignments->get($line['work_assignment_id']);
            $employee = $employees->get($line['employee_id']);

            if (! empty($filters['client_company_id'])) {
                $companyId = $assignment?->client_company_id ?? $assignment?->workLocation?->client_company_id;

                if ((int) $companyId !== (int) $filters['client_company_id']) {
                    return false;
                }
            }

            return empty($filters['department_id']) || (int) $employee?->department_id === (int) $filters['department_id'];
        })->values();

        $rows = $lines->map(function (array $line) use ($view, $assignments, $employees): array {
            $assignment = $assignments->get($line['work_assignment_id']);
            $employee = $employees->get($line['employee_id']);
            $locationType = $assignment?->workLocation?->location_type?->value ?? ($assignment?->client_company_id !== null ? 'client' : null);

            return [
                'employee_name' => $employee?->full_name ?? $line['employee_name'],
                'department_name' => $employee?->department?->name,
                ...PayrollSummaryPresenter::present($line, $view, $locationType),
            ];
        });

        $sum = fn (string $key): string => PayrollMoney::format((int) $lines->sum($key));

        return [
            'rows' => $rows,
            'meta' => [
                'period' => $payrollPeriod,
                'source' => $view['source'],
                'is_estimate' => $view['is_estimate'],
                'estimate_note' => $view['is_estimate'] ? PayrollSummaryPresenter::ESTIMATE_NOTE : null,
                'run_id' => $view['run_id'],
                'run_status' => $view['run_status'],
                'differs_from_run' => $view['differs_from_run'],
                'row_count' => $rows->count(),
                'totals' => [
                    'gross_pay' => $sum('gross_cents'),
                    'replacement_deduction' => $sum('replacement_deduction_cents'),
                    'other_deductions' => $sum('other_deduction_cents'),
                    'adjustment' => $sum('adjustment_cents'),
                    'net_payable' => $sum('net_cents'),
                ],
                'warnings' => $view['warnings'],
            ],
        ];
    }
}
