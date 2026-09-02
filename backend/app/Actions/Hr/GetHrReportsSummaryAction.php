<?php

namespace App\Actions\Hr;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use Carbon\CarbonImmutable;

/**
 * Covers docs/HRM_MARKETING_SRS.md §29's report list (employee list is the
 * existing GET /hr/employees endpoint; this covers the aggregate reports:
 * status/category/department breakdowns and a date-ranged registrations
 * count). Real Eloquent aggregation only — no PDF/Excel export in this pass.
 */
class GetHrReportsSummaryAction
{
    public function handle(?string $from, ?string $to): array
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

        return [
            'range' => ['from' => $from, 'to' => $to],
            'registrations_in_range' => $registrationsInRange,
            'status_breakdown' => $statusCounts,
            'category_breakdown' => $categoryCounts,
            'department_breakdown' => $departmentCounts,
        ];
    }
}
