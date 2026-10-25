<?php

namespace App\Support\Hr\Facility;

use App\Enums\ClientStatus;
use App\Enums\FacilityDeepCleaningState;
use App\Models\ClientCompany;
use App\Models\FacilityDeepCleaning;
use Carbon\CarbonImmutable;

/**
 * The monthly Deep Cleaning overview: one row per eligible company/guri for ONE calendar month, from recorded completions only. Computed when
 * asked; nothing is scheduled, sent or estimated, and a month with no record is never filled in.
 *
 * Calendar and dates
 *  - A completion has a plain DATE (the day the cleaning happened), so a month is the dates 1..last of that month and the boundary is exact:
 *    31 October belongs to October, 1 November to November. "Today" and the default month are the MOGADISHU calendar (FacilityCalendar,
 *    Africa/Mogadishu, UTC+3), whatever the server's own timezone: October ends at 2026-10-31 21:00:00 UTC. Dates are never converted through UTC.
 *
 * The four states of a facility for the selected month M (see FacilityDeepCleaningState)
 *  1. completed          a counted completion dated inside M.
 *  2. no_history         no counted completion dated on or before the end of M.
 *  3. overdue_multiple   no completion in M, and the last one on or before M's end lies OVERDUE_AFTER_MISSED_MONTHS or more months before M
 *                        (counted in whole calendar months: last in August, M = October -> September and October were both missed = 2).
 *  4. not_completed      no completion in M, and the last one was in the previous month (1 month missed: M itself).
 *  A completion in M always wins, so a facility that missed August and September and was cleaned in October is "completed" in October while
 *  August and September still read "not completed" / "overdue" when those months are selected, and the six-month strip shows the gap.
 *
 * Who is eligible for month M
 *  - Active facilities that existed by the end of M (registered before the Mogadishu month ended, compared as an instant).
 *  - Inactive facilities are NOT dropped silently: one with a counted completion in M is always listed (the work happened and stays visible),
 *    flagged "inactive"; with $filters['include_inactive'] every inactive facility that existed by the end of M is listed; otherwise the
 *    others are reported as a count (inactive_hidden). No deactivation date is stored, so a facility inactive today is inactive for every month.
 *  - Withdrawn completions (and those of voided inspections) count nowhere.
 */
final class FacilityDeepCleaningBuilder
{
    /** Consecutive calendar months without a completion from which a facility is "overdue for several months". */
    public const OVERDUE_AFTER_MISSED_MONTHS = 2;

    /** Months shown in each row's gap strip (the selected month and the five before it). */
    public const HISTORY_MONTHS = 6;

    /**
     * @param  array{month?: ?string, state?: ?string, search?: ?string, category?: ?string, client_company_id?: ?int, include_inactive?: ?bool}  $filters
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, month: array<string, mixed>, inactive_hidden: int}
     */
    public function build(?CarbonImmutable $today = null, array $filters = []): array
    {
        $today = ($today ?? FacilityCalendar::today())->startOfDay();
        $month = $this->month($filters['month'] ?? null, $today);
        $start = $month->startOfMonth();
        $end = $month->endOfMonth()->startOfDay();
        // A facility existed for month M if it was registered before the first instant after M ends in Mogadishu (compared as a UTC instant).
        $endExclusiveUtc = FacilityCalendar::monthEndExclusiveUtc($month);
        $includeInactive = (bool) ($filters['include_inactive'] ?? false);

        $completedHere = FacilityDeepCleaning::query()->counted()
            ->whereDate('completed_on', '>=', $start->toDateString())->whereDate('completed_on', '<=', $end->toDateString())
            ->pluck('client_company_id')->unique()->values()->all();

        $scope = fn ($q) => $q
            ->when(! empty($filters['category']), fn ($c) => $c->where('category', $filters['category']))
            ->when(! empty($filters['client_company_id']), fn ($c) => $c->where('id', (int) $filters['client_company_id']))
            ->when(! empty($filters['search']), fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim((string) $filters['search'])).'%']));

        $existed = fn ($q) => $q->where(fn ($w) => $w->where('created_at', '<', $endExclusiveUtc)->orWhereIn('id', $completedHere ?: [0]));

        $companies = ClientCompany::query()
            ->tap($scope)->tap($existed)
            ->when(! $includeInactive, fn ($q) => $q->where(fn ($w) => $w->where('status', ClientStatus::Active->value)->orWhereIn('id', $completedHere ?: [0])))
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'status']);

        $inactiveHidden = $includeInactive ? 0 : ClientCompany::query()
            ->tap($scope)->tap($existed)
            ->where('status', '!=', ClientStatus::Active->value)->whereNotIn('id', $completedHere ?: [0])->count();

        $ids = $companies->pluck('id')->all() ?: [0];
        $stripStart = $start->subMonths(self::HISTORY_MONTHS - 1);

        $last = FacilityDeepCleaning::query()->counted()->whereIn('client_company_id', $ids)->whereDate('completed_on', '<=', $end->toDateString())
            ->selectRaw('client_company_id, max(completed_on) as last_on')->groupBy('client_company_id')->pluck('last_on', 'client_company_id');

        $monthsWithCompletion = [];
        foreach (FacilityDeepCleaning::query()->counted()->whereIn('client_company_id', $ids)
            ->whereDate('completed_on', '>=', $stripStart->toDateString())->whereDate('completed_on', '<=', $end->toDateString())
            ->get(['client_company_id', 'completed_on']) as $row) {
            $monthsWithCompletion[$row->client_company_id][$row->completed_on->format('Y-m')] = max($monthsWithCompletion[$row->client_company_id][$row->completed_on->format('Y-m')] ?? '', $row->completed_on->toDateString());
        }

        $rows = [];
        $tally = array_fill_keys(FacilityDeepCleaningState::values(), 0);

        foreach ($companies as $company) {
            $lastOn = isset($last[$company->id]) ? FacilityCalendar::date((string) $last[$company->id]) : null;
            $doneInMonth = $monthsWithCompletion[$company->id][$month->format('Y-m')] ?? null;

            [$state, $missed] = $this->state($month, $doneInMonth !== null, $lastOn);
            $tally[$state->value]++;

            if (! empty($filters['state']) && $filters['state'] !== $state->value) {
                continue;
            }

            $rows[] = [
                'client_company_id' => $company->id,
                'company' => $company->name,
                'category' => $company->category->value,
                'facility_status' => $company->status->value,
                'state' => $state->value,
                'state_label' => $state->label(),
                'months_without' => $missed,
                'completed_in_month_on' => $doneInMonth,
                'last_completed_on' => $lastOn?->toDateString(),
                'history' => $this->strip($month, $monthsWithCompletion[$company->id] ?? []),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [FacilityDeepCleaningState::from($a['state'])->urgency(), $a['company']] <=> [FacilityDeepCleaningState::from($b['state'])->urgency(), $b['company']]);

        return [
            'rows' => $rows,
            'counts' => $tally + ['total' => $companies->count()],
            'month' => ['value' => $month->format('Y-m'), 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'is_current' => $month->format('Y-m') === $today->format('Y-m')],
            'inactive_hidden' => $inactiveHidden,
        ];
    }

    /**
     * The state of one facility for month $month, from whether it has a completion in that month and its last completion on or before the month's end.
     *
     * @return array{0: FacilityDeepCleaningState, 1: int} the state and how many consecutive calendar months (the selected one included) have no completion
     */
    public function state(CarbonImmutable $month, bool $completedInMonth, ?CarbonImmutable $lastOnOrBeforeMonthEnd): array
    {
        if ($completedInMonth) {
            return [FacilityDeepCleaningState::Completed, 0];
        }

        if ($lastOnOrBeforeMonthEnd === null) {
            return [FacilityDeepCleaningState::NoHistory, 0];
        }

        $missed = max(1, $this->monthIndex($month) - $this->monthIndex($lastOnOrBeforeMonthEnd));

        return [$missed >= self::OVERDUE_AFTER_MISSED_MONTHS ? FacilityDeepCleaningState::OverdueMultiple : FacilityDeepCleaningState::NotCompleted, $missed];
    }

    /** @param  array<string, string>  $byMonth  'Y-m' => latest completion date of that month */
    private function strip(CarbonImmutable $month, array $byMonth): array
    {
        $strip = [];

        for ($back = self::HISTORY_MONTHS - 1; $back >= 0; $back--) {
            $key = $month->subMonths($back)->format('Y-m');
            $strip[] = ['month' => $key, 'completed' => isset($byMonth[$key]), 'completed_on' => $byMonth[$key] ?? null];
        }

        return $strip;
    }

    private function month(?string $value, CarbonImmutable $today): CarbonImmutable
    {
        if (FacilityCalendar::isMonthKey($value)) {
            return FacilityCalendar::monthStart($value);
        }

        return FacilityCalendar::monthStart($today->format('Y-m'));
    }

    private function monthIndex(CarbonImmutable $date): int
    {
        return ((int) $date->format('Y')) * 12 + (int) $date->format('n');
    }
}
