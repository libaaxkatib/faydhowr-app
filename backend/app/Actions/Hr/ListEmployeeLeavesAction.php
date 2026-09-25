<?php

namespace App\Actions\Hr;

use App\Models\EmployeeLeave;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: a company-wide Leave list - this
 * data was previously only visible one employee at a time (Phase 4's
 * per-employee Leave History card, unchanged). Read-only, no approval
 * workflow introduced.
 */
class ListEmployeeLeavesAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = EmployeeLeave::query()->with(['employee.department', 'recordedBy']);

        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $filters['department_id']));
        }

        if (! empty($filters['leave_type'])) {
            $query->where('leave_type', $filters['leave_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('start_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('start_date', '<=', $filters['to']);
        }

        return $query
            ->orderByDesc('start_date')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
