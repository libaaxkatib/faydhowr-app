<?php

namespace App\Support\Hr\Facility;

use App\Enums\ClientStatus;
use App\Enums\FacilityDueState;
use App\Models\ClientCompany;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One row per ACTIVE company/guri with its inspection schedule, last inspection, next due date, count and today's state. Everything is read
 * from stored inspections and schedules; voided inspections are ignored. Computed when asked - nothing is scheduled or sent.
 */
final class FacilityStatusBuilder
{
    /**
     * @param  array{state?: ?string, search?: ?string, category?: ?string, client_company_id?: ?int}  $filters
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, today: string}
     */
    public function build(?CarbonImmutable $today = null, array $filters = []): array
    {
        $today = ($today ?? CarbonImmutable::now('UTC'))->startOfDay();

        $companies = ClientCompany::query()
            ->where('status', ClientStatus::Active->value)
            ->when(! empty($filters['category']), fn ($q) => $q->where('category', $filters['category']))
            ->when(! empty($filters['client_company_id']), fn ($q) => $q->where('id', (int) $filters['client_company_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim((string) $filters['search'])).'%']))
            ->orderBy('name')
            ->get(['id', 'name', 'category']);

        $ids = $companies->pluck('id')->all() ?: [0];
        $schedules = FacilityInspectionSchedule::query()->whereIn('client_company_id', $ids)->get()->keyBy('client_company_id');
        $counts = FacilityInspection::query()->counted()->whereIn('client_company_id', $ids)
            ->select('client_company_id', DB::raw('count(*) as n'), DB::raw('max(inspected_on) as last_on'))
            ->groupBy('client_company_id')->get()->keyBy('client_company_id');

        // The last counted inspection of each company (latest date, then latest record) - it carries the inspector's own follow-up date.
        $latest = [];
        foreach (FacilityInspection::query()->counted()->whereIn('client_company_id', $ids)->orderBy('inspected_on')->orderBy('id')->get(['id', 'client_company_id', 'inspected_on', 'next_follow_up_on']) as $inspection) {
            $latest[$inspection->client_company_id] = $inspection;
        }

        $rows = [];
        $tally = array_fill_keys(FacilityDueState::values(), 0);

        foreach ($companies as $company) {
            $schedule = $schedules->get($company->id);
            $last = $latest[$company->id] ?? null;
            $eval = FacilityDueCalculator::evaluate(
                $today,
                $last?->inspected_on === null ? null : CarbonImmutable::parse($last->inspected_on),
                $last?->next_follow_up_on === null ? null : CarbonImmutable::parse($last->next_follow_up_on),
                $schedule?->frequency_days,
                $schedule?->due_soon_days ?? FacilityDueCalculator::DEFAULT_DUE_SOON_DAYS,
            );
            $tally[$eval['state']->value]++;

            if (! empty($filters['state']) && $filters['state'] !== $eval['state']->value) {
                continue;
            }

            $rows[] = [
                'client_company_id' => $company->id,
                'company' => $company->name,
                'category' => $company->category->value,
                'frequency_days' => $schedule?->frequency_days,
                'due_soon_days' => $schedule?->due_soon_days,
                'last_inspected_on' => $last?->inspected_on?->toDateString(),
                'last_inspection_id' => $last?->id,
                'next_due_on' => $eval['next_due_on']?->toDateString(),
                'days_until_due' => $eval['days_until_due'],
                'days_since_last' => $eval['days_since_last'],
                'inspection_count' => (int) ($counts->get($company->id)?->n ?? 0),
                'state' => $eval['state']->value,
                'state_label' => $eval['state']->label(),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [FacilityDueState::from($a['state'])->urgency(), $a['next_due_on'] ?? '9999', $a['company']] <=> [FacilityDueState::from($b['state'])->urgency(), $b['next_due_on'] ?? '9999', $b['company']]);

        return ['rows' => $rows, 'counts' => $tally + ['total' => $companies->count()], 'today' => $today->toDateString()];
    }
}
