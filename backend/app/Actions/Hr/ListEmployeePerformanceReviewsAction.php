<?php

namespace App\Actions\Hr;

use App\Models\EmployeePerformanceReview;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: a company-wide Performance Review
 * list. Read-only, categorical ratings only (Excellent/Good/Needs
 * Improvement/Poor) - no numeric scoring introduced.
 */
class ListEmployeePerformanceReviewsAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = EmployeePerformanceReview::query()->with(['employee.department', 'reviewedBy']);

        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $filters['department_id']));
        }

        if (! empty($filters['rating'])) {
            $query->where('rating', $filters['rating']);
        }

        if (! empty($filters['from'])) {
            $query->where('review_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('review_date', '<=', $filters['to']);
        }

        return $query
            ->orderByDesc('review_date')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
