<?php

namespace App\Actions\Hr;

use App\Models\WorkforceRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: the full Workforce Request history
 * (not just open ones - the live /hr/workforce-requests page only shows
 * current cards). Read-only, paginated, no matching-behavior change.
 */
class ListWorkforceRequestHistoryAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = WorkforceRequest::query()
            ->with(['workLocation.clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee']);

        if (! empty($filters['client_company_id'])) {
            $query->whereHas('workLocation', fn ($q) => $q->where('client_company_id', $filters['client_company_id']));
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->orderByDesc('requested_date')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
