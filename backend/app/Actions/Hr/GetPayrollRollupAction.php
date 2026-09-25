<?php

namespace App\Actions\Hr;

use App\Enums\WorkAssignmentStatus;
use App\Models\EmployeeWorkAssignment;
use Illuminate\Support\Collection;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: a management-level payroll rollup
 * for a period, optionally scoped to a company/department. This is
 * deliberately NOT a second implementation of the payroll formula -
 * GetEmployeePayrollSummaryAction remains the single source of truth and is
 * called unmodified, once per matching active assignment. At current data
 * volumes this is simpler and safer than re-deriving the formula in SQL.
 */
class GetPayrollRollupAction
{
    public function __construct(private GetEmployeePayrollSummaryAction $payrollSummaryAction) {}

    public function handle(string $payrollPeriod, array $filters = []): Collection
    {
        $query = EmployeeWorkAssignment::query()
            ->where('status', WorkAssignmentStatus::Active)
            ->with(['employee.department', 'workLocation']);

        if (! empty($filters['client_company_id'])) {
            $query->whereHas('workLocation', fn ($q) => $q->where('client_company_id', $filters['client_company_id']));
        }

        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $filters['department_id']));
        }

        return $query->get()->map(fn (EmployeeWorkAssignment $assignment) => [
            'employee_id' => $assignment->employee_id,
            'employee_name' => $assignment->employee?->full_name,
            'department_name' => $assignment->employee?->department?->name,
            ...$this->payrollSummaryAction->handle($assignment->employee, $assignment, $payrollPeriod),
        ]);
    }
}
