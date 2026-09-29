<?php

namespace App\Actions\Reconciliation;

use App\Models\DataIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListDataIssuesAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = DataIssue::query()
            ->withCount('affectedRecords')
            ->with(['creator', 'resolver']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        if (! empty($filters['issue_type'])) {
            $query->where('issue_type', $filters['issue_type']);
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        if (! empty($filters['resolved_by'])) {
            $query->where('resolved_by', $filters['resolved_by']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.mb_strtolower($filters['search']).'%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(issue_number) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(title) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(source_reference) LIKE ?', [$search])
                    ->orWhereHas('affectedRecords', function ($linkQuery) use ($search) {
                        $linkQuery->where('recordable_type', 'employee')
                            ->whereHasMorph('recordable', ['employee'], function ($employeeQuery) use ($search) {
                                $employeeQuery->whereRaw('LOWER(full_name) LIKE ?', [$search])
                                    ->orWhereRaw('LOWER(employee_number) LIKE ?', [$search]);
                            });
                    });
            });
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
