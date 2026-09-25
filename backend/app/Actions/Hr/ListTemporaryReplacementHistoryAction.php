<?php

namespace App\Actions\Hr;

use App\Models\TemporaryReplacement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: the full Temporary Replacement
 * history (active and ended), filterable by company and date range. Reads
 * Phase 3 data only - no change to replacement rules.
 */
class ListTemporaryReplacementHistoryAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = TemporaryReplacement::query()
            ->with(['workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy']);

        if (! empty($filters['client_company_id'])) {
            $query->whereHas(
                'workAssignment.workLocation',
                fn ($q) => $q->where('client_company_id', $filters['client_company_id']),
            );
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
