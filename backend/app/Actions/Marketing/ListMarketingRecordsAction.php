<?php

namespace App\Actions\Marketing;

use App\Models\MarketingRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListMarketingRecordsAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = MarketingRecord::query()->with(['assignedTeam', 'assignedAdmin', 'broughtByAdmin', 'xarunDetail', 'projectDetail']);

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('record_number', 'ilike', "%{$search}%")
                    ->orWhereHas('xarunDetail', fn ($sub) => $sub->where('facility_name', 'ilike', "%{$search}%"))
                    ->orWhereHas('projectDetail', fn ($sub) => $sub->where('responsible_person_name', 'ilike', "%{$search}%"));
            });
        }

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
