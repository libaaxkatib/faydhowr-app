<?php

namespace App\Actions\Marketing;

use App\Enums\Marketing\FollowUpStatus;
use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\MarketingRecord;
use App\Models\MarketingTeam;
use App\Support\Marketing\MarketingScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Backs docs/HRM_MARKETING_SRS.md §18-19: Team A/Team B/All Teams/
 * Individual Employee reports. Real Eloquent aggregation only.
 *
 * @param  array{from?: string, to?: string, assigned_team_id?: int, assigned_admin_id?: int, status?: string, type?: string}  $filters
 */
class GetMarketingReportsSummaryAction
{
    public function handle(array $filters, Admin $actor): array
    {
        $from = $filters['from'] ?? CarbonImmutable::now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? CarbonImmutable::now()->toDateString();

        $baseQuery = MarketingScope::records(MarketingRecord::query(), $actor)->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);

        if (! empty($filters['assigned_team_id'])) {
            $baseQuery->where('assigned_team_id', $filters['assigned_team_id']);
        }

        if (! empty($filters['assigned_admin_id'])) {
            $baseQuery->where('assigned_admin_id', $filters['assigned_admin_id']);
        }

        if (! empty($filters['status'])) {
            $baseQuery->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $baseQuery->where('type', $filters['type']);
        }

        $statusBreakdown = (clone $baseQuery)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $typeBreakdown = (clone $baseQuery)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $teamBreakdown = MarketingTeam::query()
            ->when(! MarketingScope::unrestricted($actor), fn ($query) => $query->whereIn('id', MarketingScope::teamIds($actor)))
            ->when(! empty($filters['assigned_team_id']), fn ($query) => $query->where('id', $filters['assigned_team_id']))
            ->withCount(['records' => function ($query) use ($from, $to, $filters, $actor) {
                MarketingScope::records($query, $actor);
                $query->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);

                if (! empty($filters['assigned_admin_id'])) {
                    $query->where('assigned_admin_id', $filters['assigned_admin_id']);
                }
                if (! empty($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (! empty($filters['type'])) {
                    $query->where('type', $filters['type']);
                }
            }])
            ->orderBy('name')
            ->get()
            ->map(fn (MarketingTeam $team) => ['id' => $team->id, 'name' => $team->name, 'total' => $team->records_count]);

        $employeeBreakdown = (clone $baseQuery)
            ->whereNotNull('assigned_admin_id')
            ->selectRaw('assigned_admin_id, count(*) as total')
            ->groupBy('assigned_admin_id')
            ->with('assignedAdmin')
            ->get()
            ->map(fn (MarketingRecord $record) => [
                'admin_id' => $record->assigned_admin_id,
                'admin_name' => $record->assignedAdmin?->full_name,
                'total' => $record->total,
            ]);

        return [
            'range' => ['from' => $from, 'to' => $to],
            'total_records' => (clone $baseQuery)->count(),
            'status_breakdown' => $statusBreakdown,
            'type_breakdown' => $typeBreakdown,
            'team_breakdown' => $teamBreakdown,
            'employee_breakdown' => $employeeBreakdown,
            'type_status_breakdown' => $this->typeStatusBreakdown($baseQuery),
            'follow_up_summary' => $this->followUpSummary($baseQuery),
        ];
    }

    /**
     * XARUN and PROJECT side by side: how many records of each type are in each status (the same records, scope and filters as the totals above).
     *
     * @param  Builder<MarketingRecord>  $records
     * @return array<string, array<string, int>>
     */
    private function typeStatusBreakdown(Builder $records): array
    {
        $out = [];

        foreach ((clone $records)->selectRaw('type, status, count(*) as total')->groupBy('type', 'status')->get() as $row) {
            $out[$row->type->value ?? (string) $row->type][$row->status->value ?? (string) $row->status] = (int) $row->total;
        }

        ksort($out);
        foreach ($out as &$statuses) {
            ksort($statuses);
        }

        return $out;
    }

    /**
     * The follow-ups of the records counted above (so the team scope and every filter apply to them too): how many are scheduled, rescheduled or
     * completed, how many open ones are overdue or due today, and the open work of each person. Read-only; nothing here changes a follow-up.
     *
     * @param  Builder<MarketingRecord>  $records
     * @return array{by_status: array<string, int>, open: int, overdue: int, due_today: int, workload: list<array{admin_id: int|null, admin_name: string|null, open: int, overdue: int}>}
     */
    private function followUpSummary(Builder $records): array
    {
        $today = CarbonImmutable::now()->toDateString();
        $open = [FollowUpStatus::Scheduled->value, FollowUpStatus::Rescheduled->value];
        $followUps = FollowUp::query()->whereIn('marketing_record_id', (clone $records)->select('marketing_records.id'));

        $byStatus = [];
        foreach ((clone $followUps)->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            $byStatus[$row->status->value ?? (string) $row->status] = (int) $row->total;
        }

        ksort($byStatus);

        $openQuery = (clone $followUps)->whereIn('status', $open);

        $workload = (clone $openQuery)
            ->selectRaw('assigned_admin_id, count(*) as open_total, sum(case when follow_up_date < ? then 1 else 0 end) as overdue_total', [$today])
            ->groupBy('assigned_admin_id')
            ->with('assignedAdmin')
            ->get()
            ->map(fn (FollowUp $row): array => [
                'admin_id' => $row->assigned_admin_id,
                'admin_name' => $row->assignedAdmin?->full_name,
                'open' => (int) $row->open_total,
                'overdue' => (int) $row->overdue_total,
            ])
            ->sortByDesc('open')
            ->values()
            ->all();

        return [
            'by_status' => $byStatus,
            'open' => (clone $openQuery)->count(),
            'overdue' => (clone $openQuery)->whereDate('follow_up_date', '<', $today)->count(),
            'due_today' => (clone $openQuery)->whereDate('follow_up_date', $today)->count(),
            'workload' => $workload,
        ];
    }
}
