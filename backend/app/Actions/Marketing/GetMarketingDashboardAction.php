<?php

namespace App\Actions\Marketing;

use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\MarketingRecord;
use App\Support\Marketing\MarketingScope;
use Carbon\CarbonImmutable;

/**
 * Backs docs/HRM_MARKETING_SRS.md §33's action-oriented Marketing TODAY
 * panel (Follow-ups / New Leads / Quotations / Overdue) — real Eloquent
 * counts only, no fabricated statistics.
 */
class GetMarketingDashboardAction
{
    public function handle(Admin $actor): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $startOfDay = CarbonImmutable::now()->startOfDay();

        $statusCounts = MarketingScope::records(MarketingRecord::query(), $actor)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $typeCounts = MarketingScope::records(MarketingRecord::query(), $actor)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'total_records' => MarketingScope::records(MarketingRecord::query(), $actor)->count(),
            'total_xarun' => (int) ($typeCounts['xarun'] ?? 0),
            'total_project' => (int) ($typeCounts['project'] ?? 0),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'quotation' => (int) ($statusCounts['quotation'] ?? 0),
            'done' => (int) ($statusCounts['done'] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            'new_leads_today' => MarketingScope::records(MarketingRecord::query(), $actor)->where('created_at', '>=', $startOfDay)->count(),
            'todays_follow_ups' => MarketingScope::followUps(FollowUp::query(), $actor)
                ->whereDate('follow_up_date', $today)
                ->whereIn('status', ['scheduled', 'rescheduled'])
                ->count(),
            'overdue_follow_ups' => MarketingScope::followUps(FollowUp::query(), $actor)
                ->whereDate('follow_up_date', '<', $today)
                ->whereIn('status', ['scheduled', 'rescheduled'])
                ->count(),
        ];
    }
}
