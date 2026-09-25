<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListEmployeesAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = Employee::query()->with(['category', 'department', 'position', 'activeWorkAssignments.workLocation.clientCompany']);

        if (($filters['status'] ?? null) === 'waiting') {
            $query->with('workforceRequestMatches');
        }

        if (($filters['status'] ?? null) === 'inactive') {
            $query->with('latestSeparation.separatedBy');
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%")
                    ->orWhere('employee_number', 'ilike', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['pipeline_stage'])) {
            $query->where('pipeline_stage', $filters['pipeline_stage']);
        }

        if (! empty($filters['employee_category_id'])) {
            $query->where('employee_category_id', $filters['employee_category_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['position_id'])) {
            $query->where('position_id', $filters['position_id']);
        }

        if (! empty($filters['is_supervisor'])) {
            $query->where('is_supervisor', true);
        }

        if (! empty($filters['office_only'])) {
            $query->whereHas('activeWorkAssignments.workLocation', fn ($q) => $q->where('location_type', 'office'));
        }

        return $query
            ->orderByDesc('application_date')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
