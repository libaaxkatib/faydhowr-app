<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollPeriodView;
use App\Support\Hr\Payroll\PayrollSummaryPresenter;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Read-only payroll summary for ONE assignment and month. It no longer has a
 * formula of its own: it reads the same answer the payroll run gives
 * (PayrollPeriodView), so the two can never disagree.
 *
 *  - Month has an approved/finalized run: the stored line is shown (final).
 *  - Otherwise: the payroll engine's live calculation, clearly marked as an
 *    ESTIMATE (is_estimate = true + estimate_note), with `differs_from_run`
 *    set when a draft run for the month no longer matches.
 *  - Covers calendar-day proration from the effective-dated salary history,
 *    final pay after a permanent separation, Temporary-replacement costs,
 *    approved unpaid leave, absence, penalties, advances and adjustments.
 *  - An assignment with no payroll line for the month (Temporary employee,
 *    cancelled, outside the month) is returned as `payable: false` with the
 *    reason, never as an invented full-month salary.
 *  - Overtime is never included (client rule: no overtime; office rule: pending).
 */
class GetEmployeePayrollSummaryAction
{
    public function __construct(private readonly PayrollPeriodView $periodView = new PayrollPeriodView) {}

    public function handle(Employee $employee, EmployeeWorkAssignment $assignment, string $payrollPeriod): array
    {
        if ((int) $assignment->employee_id !== (int) $employee->id) {
            throw new HttpResponseException(
                ApiResponse::error(
                    'This work assignment does not belong to this employee.',
                    'WORK_ASSIGNMENT_NOT_FOUND_FOR_EMPLOYEE',
                    422,
                ),
            );
        }

        $view = $this->periodView->forPeriod($payrollPeriod, [$employee->id]);
        $locationType = $assignment->workLocation?->location_type?->value ?? ($assignment->client_company_id !== null ? 'client' : null);
        $line = collect($view['lines'])->firstWhere('work_assignment_id', $assignment->id);

        // A live estimate was calculated for this employee only, so all of its warnings are theirs. A stored run's
        // warnings cover the whole month, so keep only the ones that mention this assignment or employee.
        $related = $view['is_estimate']
            ? $view['warnings']
            : array_values(array_filter(
                $view['warnings'],
                fn (string $w): bool => str_contains($w, "#{$assignment->id}") || str_contains($w, (string) $employee->employee_number),
            ));

        if ($line === null) {
            return [...PayrollSummaryPresenter::notPayable($employee->id, $assignment->id, $locationType, $view, $related), 'warnings' => $related];
        }

        return [...PayrollSummaryPresenter::present($line, $view, $locationType), 'warnings' => $related];
    }
}
