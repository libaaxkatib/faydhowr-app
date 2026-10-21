<?php

namespace App\Support\Hr\Facility;

use App\Enums\FacilityComplaintStatus;
use App\Enums\FacilityComplaintType;
use App\Enums\FacilityDueState;
use App\Enums\FacilityItemKey;
use App\Enums\FacilitySatisfaction;
use App\Models\FacilityComplaint;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionItem;
use App\Models\FacilityUniformIssue;
use Carbon\CarbonImmutable;

/**
 * The Facility reports, computed from stored inspections, complaints and schedules ONLY. Voided records are left out. Nothing is estimated:
 * "not recorded" stays "not recorded" (a complaint with no documented feedback is counted as such, never as satisfied or unsatisfied, and a
 * company with no schedule is never "on time"). The totals respect the filters (period, company, complaint type).
 */
final class FacilityReportBuilder
{
    public function __construct(private readonly FacilityStatusBuilder $status = new FacilityStatusBuilder) {}

    /**
     * @param  array{from?: ?string, to?: ?string, client_company_id?: ?int, type?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function build(?CarbonImmutable $today = null, array $filters = []): array
    {
        $today = ($today ?? CarbonImmutable::now('UTC'))->startOfDay();
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'])->startOfDay() : $today;
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'])->startOfDay() : $to->subMonths(6)->addDay();
        $companyId = isset($filters['client_company_id']) ? (int) $filters['client_company_id'] : null;
        $type = $filters['type'] ?? null;
        $rangeDays = max(1, (int) $from->diffInDays($to) + 1);

        $inspections = FacilityInspection::query()->counted()
            ->whereDate('inspected_on', '>=', $from->toDateString())->whereDate('inspected_on', '<=', $to->toDateString())
            ->when($companyId, fn ($q) => $q->where('client_company_id', $companyId))
            ->get(['id', 'client_company_id', 'inspected_on']);

        $complaints = FacilityComplaint::query()->counted()
            ->whereDate('reported_on', '>=', $from->toDateString())->whereDate('reported_on', '<=', $to->toDateString())
            ->when($companyId, fn ($q) => $q->where('client_company_id', $companyId))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->get();

        $statusFilters = $companyId ? ['client_company_id' => $companyId] : [];
        $status = $this->status->build($today, $statusFilters);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $rangeDays],
            'inspections' => $this->inspectionTotals($inspections, $status),
            'items' => $this->itemTotals($inspections),
            'complaints' => $this->complaintTotals($complaints),
            'satisfaction' => $this->satisfactionTotals($complaints),
            'trends' => $this->trends($from, $to, $inspections, $complaints),
            'schedule_status' => ['counts' => $status['counts'], 'overdue' => $this->pick($status['rows'], FacilityDueState::Overdue), 'due' => $this->pick($status['rows'], FacilityDueState::Due), 'never_inspected' => $this->pick($status['rows'], FacilityDueState::NeverInspected)],
            'infrequent' => $this->infrequent($status['rows'], $inspections, $rangeDays),
            'by_company' => $this->byCompany($status['rows'], $inspections, $complaints),
        ];
    }

    /** @return array<string, mixed> */
    private function inspectionTotals($inspections, array $status): array
    {
        return [
            'count' => $inspections->count(),
            'companies_inspected' => $inspections->pluck('client_company_id')->unique()->count(),
            'companies_in_scope' => $status['counts']['total'],
            'never_inspected' => $status['counts'][FacilityDueState::NeverInspected->value],
            'overdue' => $status['counts'][FacilityDueState::Overdue->value],
            'due' => $status['counts'][FacilityDueState::Due->value],
            'due_soon' => $status['counts'][FacilityDueState::DueSoon->value],
        ];
    }

    /** @return array<string, mixed> */
    private function itemTotals($inspections): array
    {
        $ids = $inspections->pluck('id')->all() ?: [0];
        $items = FacilityInspectionItem::query()->whereIn('facility_inspection_id', $ids)->get(['item_key', 'applicable', 'result']);
        $byItem = [];

        foreach (FacilityItemKey::cases() as $key) {
            $rows = $items->filter(fn ($i): bool => $i->item_key === $key && $i->applicable);
            $byItem[] = [
                'item_key' => $key->value,
                'label' => $key->label(),
                'checked' => $rows->count(),
                'unsatisfactory' => $rows->filter(fn ($i): bool => $i->result?->value === 'unsatisfactory')->count(),
            ];
        }

        return [
            'by_item' => $byItem,
            'unsatisfactory_total' => array_sum(array_column($byItem, 'unsatisfactory')),
            'uniform_issues' => FacilityUniformIssue::query()->whereIn('facility_inspection_id', $ids)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function complaintTotals($complaints): array
    {
        $byStatus = [];
        foreach (FacilityComplaintStatus::cases() as $status) {
            $byStatus[$status->value] = $complaints->filter(fn (FacilityComplaint $c): bool => $c->status === $status)->count();
        }
        $byType = [];
        foreach (FacilityComplaintType::cases() as $type) {
            $rows = $complaints->filter(fn (FacilityComplaint $c): bool => $c->type === $type);
            $byType[$type->value] = ['total' => $rows->count(), 'unresolved' => $rows->filter(fn (FacilityComplaint $c): bool => $c->status->isUnresolved())->count()];
        }

        $responded = $complaints->filter(fn (FacilityComplaint $c): bool => $c->responded_on !== null);
        $resolved = $complaints->filter(fn (FacilityComplaint $c): bool => $c->status === FacilityComplaintStatus::Resolved && $c->resolved_on !== null);
        $days = fn ($rows, string $to): ?float => $rows->isEmpty() ? null : round($rows->avg(fn (FacilityComplaint $c): int => (int) $c->reported_on->diffInDays($c->{$to}, false)), 1);

        return [
            'total' => $complaints->count(),
            'by_status' => $byStatus,
            'by_type' => $byType,
            // Answered is NOT resolved: unresolved = every complaint that is not resolved (open, in progress and answered).
            'answered' => $byStatus['answered'],
            'resolved' => $byStatus['resolved'],
            'unresolved' => $complaints->filter(fn (FacilityComplaint $c): bool => $c->status->isUnresolved())->count(),
            'average_days_to_response' => $days($responded, 'responded_on'),
            'average_days_to_resolution' => $days($resolved, 'resolved_on'),
        ];
    }

    /** @return array<string, mixed> */
    private function satisfactionTotals($complaints): array
    {
        $satisfied = $complaints->filter(fn (FacilityComplaint $c): bool => $c->satisfaction === FacilitySatisfaction::Satisfied)->count();
        $not = $complaints->filter(fn (FacilityComplaint $c): bool => $c->satisfaction === FacilitySatisfaction::NotSatisfied)->count();

        // "not_recorded" is its own figure: absence of documented feedback is neither positive nor negative.
        return ['satisfied' => $satisfied, 'not_satisfied' => $not, 'not_recorded' => $complaints->count() - $satisfied - $not];
    }

    /** @return list<array<string, mixed>> */
    private function trends(CarbonImmutable $from, CarbonImmutable $to, $inspections, $complaints): array
    {
        $months = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'inspections' => 0, 'complaints_reported' => 0, 'complaints_resolved' => 0];
        }
        foreach ($inspections as $i) {
            $key = CarbonImmutable::parse($i->inspected_on)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['inspections']++;
            }
        }
        foreach ($complaints as $c) {
            $reported = $c->reported_on->format('Y-m');
            if (isset($months[$reported])) {
                $months[$reported]['complaints_reported']++;
            }
            if ($c->status === FacilityComplaintStatus::Resolved && $c->resolved_on !== null) {
                $resolved = $c->resolved_on->format('Y-m');
                if (isset($months[$resolved])) {
                    $months[$resolved]['complaints_resolved']++;
                }
            }
        }

        return array_values($months);
    }

    /**
     * Companies inspected LESS often than their own schedule asks, over the chosen period: expected = whole periods of the company's
     * frequency that fit in the range; actual = inspections in the range. Only companies WITH a frequency can be judged - the rest are not guessed.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function infrequent(array $rows, $inspections, int $rangeDays): array
    {
        $actual = $inspections->groupBy('client_company_id')->map->count();
        $out = [];

        foreach ($rows as $row) {
            if (! $row['frequency_days'] || $row['frequency_days'] <= 0) {
                continue;
            }
            $expected = intdiv($rangeDays, (int) $row['frequency_days']);
            $done = (int) ($actual->get($row['client_company_id']) ?? 0);

            if ($expected > 0 && $done < $expected) {
                $out[] = ['client_company_id' => $row['client_company_id'], 'company' => $row['company'], 'frequency_days' => $row['frequency_days'], 'expected_inspections' => $expected, 'actual_inspections' => $done, 'last_inspected_on' => $row['last_inspected_on']];
            }
        }

        usort($out, fn (array $a, array $b): int => [$a['actual_inspections'] - $a['expected_inspections'], $a['company']] <=> [$b['actual_inspections'] - $b['expected_inspections'], $b['company']]);

        return $out;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function pick(array $rows, FacilityDueState $state): array
    {
        return array_values(array_map(
            fn (array $r): array => ['client_company_id' => $r['client_company_id'], 'company' => $r['company'], 'last_inspected_on' => $r['last_inspected_on'], 'next_due_on' => $r['next_due_on'], 'days_until_due' => $r['days_until_due']],
            array_filter($rows, fn (array $r): bool => $r['state'] === $state->value),
        ));
    }

    /**
     * One row per company that has an inspection or a complaint in the period (the drill-down starting point).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function byCompany(array $rows, $inspections, $complaints): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = $row['client_company_id'];
            $i = $inspections->where('client_company_id', $id)->count();
            $c = $complaints->where('client_company_id', $id);

            if ($i === 0 && $c->isEmpty()) {
                continue;
            }
            $out[] = [
                'client_company_id' => $id,
                'company' => $row['company'],
                'inspections' => $i,
                'complaints' => $c->count(),
                'unresolved_complaints' => $c->filter(fn (FacilityComplaint $x): bool => $x->status->isUnresolved())->count(),
                'satisfied' => $c->filter(fn (FacilityComplaint $x): bool => $x->satisfaction === FacilitySatisfaction::Satisfied)->count(),
                'not_satisfied' => $c->filter(fn (FacilityComplaint $x): bool => $x->satisfaction === FacilitySatisfaction::NotSatisfied)->count(),
                'state' => $row['state'],
            ];
        }

        return $out;
    }
}
