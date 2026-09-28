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
            $search = '%'.mb_strtolower($filters['search']).'%';
            $query->where(function ($q) use ($search) {
                // LOWER(...) LIKE, not ILIKE: portable across Postgres (production) and
                // SQLite (the test suite's DB driver) rather than a Postgres-only operator.
                $q->whereRaw('LOWER(full_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(employee_number) LIKE ?', [$search]);
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

        if (! empty($filters['location'])) {
            $query->whereRaw('LOWER(location) LIKE ?', ['%'.mb_strtolower($filters['location']).'%']);
        }

        if (! empty($filters['is_supervisor'])) {
            $query->where('is_supervisor', true);
        }

        if (! empty($filters['office_only'])) {
            $query->whereHas('activeWorkAssignments.workLocation', fn ($q) => $q->where('location_type', 'office'));
        }

        // array_key_exists, not empty(): both true and false are meaningful filter
        // values here (e.g. "Profile Incomplete" must be queryable, not just "Complete").
        if (array_key_exists('profile_complete', $filters)) {
            $query->where('profile_complete', $filters['profile_complete']);
        }

        if (array_key_exists('guarantor_needed', $filters)) {
            $query->where('guarantor_needed', $filters['guarantor_needed']);
        }

        if (! empty($filters['application_date_from'])) {
            $query->whereDate('application_date', '>=', $filters['application_date_from']);
        }

        if (! empty($filters['application_date_to'])) {
            $query->whereDate('application_date', '<=', $filters['application_date_to']);
        }

        if (! empty($filters['joining_date_from'])) {
            $query->whereDate('joining_date', '>=', $filters['joining_date_from']);
        }

        if (! empty($filters['joining_date_to'])) {
            $query->whereDate('joining_date', '<=', $filters['joining_date_to']);
        }

        return $query
            ->orderByDesc('application_date')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
