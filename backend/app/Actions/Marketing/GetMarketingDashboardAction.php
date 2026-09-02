<?php

namespace App\Actions\Marketing;

use App\Models\FollowUp;
use App\Models\MarketingRecord;
use Carbon\CarbonImmutable;

/**
 * Backs docs/HRM_MARKETING_SRS.md §33's action-oriented Marketing TODAY
 * panel (Follow-ups / New Leads / Quotations / Overdue) — real Eloquent
 * counts only, no fabricated statistics.
 */
class GetMarketingDashboardAction
{
    public function handle(): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $startOfDay = CarbonImmutable::now()->startOfDay();

        $statusCounts = MarketingRecord::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $typeCounts = MarketingRecord::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'total_records' => MarketingRecord::query()->count(),
            'total_xarun' => (int) ($typeCounts['xarun'] ?? 0),
            'total_project' => (int) ($typeCounts['project'] ?? 0),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'quotation' => (int) ($statusCounts['quotation'] ?? 0),
            'done' => (int) ($statusCounts['done'] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            'new_leads_today' => MarketingRecord::query()->where('created_at', '>=', $startOfDay)->count(),
            'todays_follow_ups' => FollowUp::query()
                ->whereDate('follow_up_date', $today)
                ->whereIn('status', ['scheduled', 'rescheduled'])
                ->count(),
            'overdue_follow_ups' => FollowUp::query()
                ->whereDate('follow_up_date', '<', $today)
                ->whereIn('status', ['scheduled', 'rescheduled'])
                ->count(),
        ];
    }
}
