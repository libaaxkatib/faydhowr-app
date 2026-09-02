<?php

namespace App\Actions\Marketing;

use App\Models\MarketingRecord;
use App\Models\MarketingTeam;
use Carbon\CarbonImmutable;

/**
 * Backs docs/HRM_MARKETING_SRS.md §18-19: Team A/Team B/All Teams/
 * Individual Employee reports. Real Eloquent aggregation only.
 *
 * @param  array{from?: string, to?: string, assigned_team_id?: int, assigned_admin_id?: int, status?: string, type?: string}  $filters
 */
class GetMarketingReportsSummaryAction
{
    public function handle(array $filters): array
    {
        $from = $filters['from'] ?? CarbonImmutable::now()->subDays(30)->toDateString();
        $to = $filters['to'] ?? CarbonImmutable::now()->toDateString();

        $baseQuery = MarketingRecord::query()->whereBetween('created_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);

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
            ->when(! empty($filters['assigned_team_id']), fn ($query) => $query->where('id', $filters['assigned_team_id']))
            ->withCount(['records' => function ($query) use ($from, $to, $filters) {
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
        ];
    }
}
