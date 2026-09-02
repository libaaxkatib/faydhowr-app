<?php

namespace App\Actions\Hr;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use Carbon\CarbonImmutable;

/**
 * Real Eloquent counts only, per docs/HRM_MARKETING_SRS.md §28 — no fabricated
 * statistics. Categories/counts are 0 where there is no data yet.
 */
class GetHrDashboardAction
{
    public function handle(): array
    {
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

        $newApplicants = Employee::query()
            ->where('status', EmployeeStatus::Applicant->value)
            ->where('application_date', '>=', CarbonImmutable::now()->subDays(30)->toDateString())
            ->count();

        return [
            'total_employees' => Employee::query()->count(),
            'active_employees' => (int) ($statusCounts[EmployeeStatus::Active->value] ?? 0),
            'inactive_employees' => (int) ($statusCounts[EmployeeStatus::Inactive->value] ?? 0),
            'new_applicants' => $newApplicants,
            'practical' => (int) ($statusCounts[EmployeeStatus::Practical->value] ?? 0),
            'waiting' => (int) ($statusCounts[EmployeeStatus::Waiting->value] ?? 0),
            'approved' => (int) ($statusCounts[EmployeeStatus::Approved->value] ?? 0),
            'category_breakdown' => $categoryCounts,
        ];
    }
}
