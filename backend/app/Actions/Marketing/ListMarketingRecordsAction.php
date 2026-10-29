<?php

namespace App\Actions\Marketing;

use App\Models\Admin;
use App\Models\MarketingRecord;
use App\Support\Marketing\MarketingScope;
use App\Support\Marketing\MarketingSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListMarketingRecordsAction
{
    public function handle(array $filters, Admin $actor): LengthAwarePaginator
    {
        $query = MarketingScope::records(
            MarketingRecord::query()->with(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'xarunDetail', 'projectDetail']),
            $actor,
        );

        MarketingSearch::applyToRecords($query, $filters['search'] ?? null);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['assigned_team_id'])) {
            $query->where('assigned_team_id', $filters['assigned_team_id']);
        }

        if (! empty($filters['assigned_admin_id'])) {
            $query->where('assigned_admin_id', $filters['assigned_admin_id']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
