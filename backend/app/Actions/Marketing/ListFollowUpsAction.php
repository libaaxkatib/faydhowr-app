<?php

namespace App\Actions\Marketing;

use App\Models\FollowUp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Backs docs/HRM_MARKETING_SRS.md §16's Today / Upcoming / Overdue views.
 * "Overdue" = still scheduled/rescheduled but the date has passed;
 * completed follow-ups are never overdue.
 */
class ListFollowUpsAction
{
    public function handle(array $filters): Collection
    {
        $query = FollowUp::query()->with(['marketingRecord.xarunDetail', 'marketingRecord.projectDetail', 'assignedAdmin']);

        if (! empty($filters['assigned_admin_id'])) {
            $query->where('assigned_admin_id', $filters['assigned_admin_id']);
        }

        $today = CarbonImmutable::now()->toDateString();

        match ($filters['filter'] ?? 'all') {
            'today' => $query->whereDate('follow_up_date', $today)->whereIn('status', ['scheduled', 'rescheduled']),
            'upcoming' => $query->whereDate('follow_up_date', '>', $today)->whereIn('status', ['scheduled', 'rescheduled']),
            'overdue' => $query->whereDate('follow_up_date', '<', $today)->whereIn('status', ['scheduled', 'rescheduled']),
            default => null,
        };

        return $query->orderBy('follow_up_date')->get();
    }
}
