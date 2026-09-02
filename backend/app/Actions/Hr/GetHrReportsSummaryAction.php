<?php

namespace App\Actions\Hr;

use App\Enums\WorkAssignmentStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;

/**
 * Covers docs/HRM_MARKETING_SRS.md §29's report list (employee list is the
 * existing GET /hr/employees endpoint; this covers the aggregate reports:
 * status/category/department breakdowns and a date-ranged registrations
 * count), plus the Work Assignments requirement's §8 workplace/salary
 * reporting. Real Eloquent aggregation only — no PDF/Excel export in this
 * pass, and no salary formula/calculation invented.
 */
class GetHrReportsSummaryAction
{
    public function handle(?string $from, ?string $to, array $filters = []): array
    {
        $from ??= CarbonImmutable::now()->subDays(30)->toDateString();
        $to ??= CarbonImmutable::now()->toDateString();

        $statusCounts = Employee::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $categoryCounts = EmployeeCategory::query()
            ->withCount('employees')
            ->orderBy('name')
            ->get()
            ->map(fn (EmployeeCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'total' => $category->employees_count,
            ]);

        $departmentCounts = Department::query()
            ->withCount('employees')
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department) => [
                'id' => $department->id,
                'name' => $department->name,
                'total' => $department->employees_count,
            ]);

        $registrationsInRange = Employee::query()
            ->whereBetween('application_date', [$from, $to])
            ->count();

        $assignmentQuery = EmployeeWorkAssignment::query();

        if (! empty($filters['client_company_id'])) {
            $assignmentQuery->whereHas(
                'workLocation',
                fn ($q) => $q->where('client_company_id', $filters['client_company_id']),
            );
        }

        if (! empty($filters['work_location_id'])) {
            $assignmentQuery->where('employee_work_assignments.work_location_id', $filters['work_location_id']);
        }

        if (! empty($filters['assignment_status'])) {
            $assignmentQuery->where('employee_work_assignments.status', $filters['assignment_status']);
        } else {
            $assignmentQuery->where('employee_work_assignments.status', WorkAssignmentStatus::Active);
        }

        $assignmentCountsByLocation = (clone $assignmentQuery)
            ->selectRaw('employee_work_assignments.work_location_id, count(*) as total')
            ->groupBy('employee_work_assignments.work_location_id')
            ->get()
            ->keyBy('work_location_id');

        $workplaceBreakdown = WorkLocation::query()
            ->with('clientCompany')
            ->orderBy('name')
            ->get()
            ->map(fn (WorkLocation $location) => [
                'id' => $location->id,
                'name' => $location->name,
                'location_type' => $location->location_type->value,
                'client_company_name' => $location->clientCompany?->name,
                'capacity' => $location->capacity,
                'total' => (int) ($assignmentCountsByLocation[$location->id]->total ?? 0),
            ]);

        $companySalaryTotals = (clone $assignmentQuery)
            ->join('work_locations', 'work_locations.id', '=', 'employee_work_assignments.work_location_id')
            ->join('client_companies', 'client_companies.id', '=', 'work_locations.client_company_id')
            ->selectRaw('client_companies.id, client_companies.name, sum(employee_work_assignments.salary_amount) as total_salary, count(*) as total_assignments')
            ->groupBy('client_companies.id', 'client_companies.name')
            ->orderBy('client_companies.name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'total_salary' => $row->total_salary,
                'total_assignments' => (int) $row->total_assignments,
            ]);

        return [
            'range' => ['from' => $from, 'to' => $to],
            'registrations_in_range' => $registrationsInRange,
            'status_breakdown' => $statusCounts,
            'category_breakdown' => $categoryCounts,
            'department_breakdown' => $departmentCounts,
            'workplace_breakdown' => $workplaceBreakdown,
            'company_salary_totals' => $companySalaryTotals,
        ];
    }
}
