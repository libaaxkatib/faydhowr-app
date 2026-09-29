<?php

namespace App\Actions\Reconciliation;

use App\Enums\DataIssueSeverity;
use App\Enums\DataIssueStatus;
use App\Models\DataIssue;

class GetDataIssueSummaryAction
{
    /**
     * Issue-management statistics only — deliberately never mixed with any
     * HR employee KPI.
     *
     * @return array{
     *     total: int, open: int, investigating: int, resolved: int,
     *     accepted_difference: int, cannot_resolve: int, critical: int, high: int
     * }
     */
    public function handle(): array
    {
        $statusCounts = DataIssue::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $severityCounts = DataIssue::query()
            ->selectRaw('severity, count(*) as total')
            ->groupBy('severity')
            ->pluck('total', 'severity');

        return [
            'total' => (int) $statusCounts->sum(),
            'open' => (int) ($statusCounts[DataIssueStatus::Open->value] ?? 0),
            'investigating' => (int) ($statusCounts[DataIssueStatus::Investigating->value] ?? 0),
            'resolved' => (int) ($statusCounts[DataIssueStatus::Resolved->value] ?? 0),
            'accepted_difference' => (int) ($statusCounts[DataIssueStatus::AcceptedDifference->value] ?? 0),
            'cannot_resolve' => (int) ($statusCounts[DataIssueStatus::CannotResolve->value] ?? 0),
            'critical' => (int) ($severityCounts[DataIssueSeverity::Critical->value] ?? 0),
            'high' => (int) ($severityCounts[DataIssueSeverity::High->value] ?? 0),
        ];
    }
}
