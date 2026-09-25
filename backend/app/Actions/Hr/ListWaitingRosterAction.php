<?php

namespace App\Actions\Hr;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: the Waiting Roster, sortable by
 * waiting_since (oldest-first is the default "who's been waiting longest"
 * view). A null waiting_since (pre-Phase-2 data) sorts last in either
 * direction rather than being hidden or fabricated - see
 * GetHrReportsSummaryAction's waiting_duration_buckets for the same honesty
 * principle applied to the aggregate view.
 */
class ListWaitingRosterAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $direction = ($filters['order_by'] ?? 'oldest') === 'newest' ? 'desc' : 'asc';

        return Employee::query()
            ->where('status', EmployeeStatus::Waiting)
            ->with(['category', 'position'])
            ->orderByRaw('waiting_since IS NULL')
            ->orderBy('waiting_since', $direction)
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
