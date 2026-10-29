<?php

namespace App\Support\Hr;

use App\Models\TemporaryReplacement;
use App\Support\Hr\Facility\FacilityCalendar;
use App\Support\Hr\Payroll\CoveragePeriod;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use App\Support\Hr\Payroll\ReplacementCostService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * What a change of a Temporary cover's dates would do, worked out WITHOUT saving anything (docs/HR_PAYROLL_RULES.md U8, N9-N11).
 *
 * The days are the cover's coverage days (CoveragePeriod: every calendar day from the actual start to the actual last day, both inclusive, never later
 * than today and never outside the covered assignment). The cost of each month is the existing ReplacementCostService figure for the same days, so the
 * preview can never disagree with the payroll run. A month is "locked" when its payroll is approved or finalized (PayrollPeriodLock): a change that
 * reaches a locked month is a correction of closed payroll and is handled by ClosedPayrollCorrection (Super Admin, with a reason).
 */
final class ReplacementChangeImpact
{
    /** The most months looked at in one preview (a start date years in the past stays answerable). */
    private const MAX_MONTHS = 48;

    public function __construct(
        private readonly ReplacementCostService $costs = new ReplacementCostService,
        private readonly PayrollPeriodLock $lock = new PayrollPeriodLock,
    ) {}

    /**
     * @param  array{start_date?: string, end_date?: ?string}  $new  the dates after the change (a missing key keeps the current value; end_date null = open end)
     * @return array<string, mixed>
     */
    public function forChange(TemporaryReplacement $replacement, array $new, bool $withMoney): array
    {
        $oldStart = $replacement->start_date->toDateString();
        $oldEnd = $replacement->end_date?->toDateString();
        $newStart = $new['start_date'] ?? $oldStart;
        $newEnd = array_key_exists('end_date', $new) ? $new['end_date'] : $oldEnd;

        $clone = $this->withDates($replacement, $newStart, $newEnd);

        $before = $this->days($replacement);
        $after = $this->days($clone);
        $added = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        $periods = array_values(array_unique(array_map(fn (string $d): string => substr($d, 0, 7), [...$before, ...$after])));
        sort($periods);
        $periods = array_slice($periods, 0, self::MAX_MONTHS);

        $months = [];
        $touchesLocked = false;
        $lockedChanged = false;

        foreach ($periods as $period) {
            $locked = $this->lock->isLocked($period);
            $daysBefore = count(array_filter($before, fn (string $d): bool => str_starts_with($d, $period.'-')));
            $daysAfter = count(array_filter($after, fn (string $d): bool => str_starts_with($d, $period.'-')));
            $changed = array_values(array_filter([...$added, ...$removed], fn (string $d): bool => str_starts_with($d, $period.'-'))) !== [];

            $touchesLocked = $touchesLocked || $locked;
            $lockedChanged = $lockedChanged || ($locked && $changed);

            $row = ['period' => $period, 'locked' => $locked, 'days_before' => $daysBefore, 'days_after' => $daysAfter, 'changed' => $changed];

            if ($withMoney) {
                $row['cost_before_cents'] = $this->cost($replacement, $period);
                $row['cost_after_cents'] = $this->cost($clone, $period);
            }

            $months[] = $row;
        }

        $extendsEnd = $oldEnd !== null && ($newEnd === null || $newEnd > $oldEnd);

        $result = [
            'old' => ['start_date' => $oldStart, 'end_date' => $oldEnd],
            'new' => ['start_date' => $newStart, 'end_date' => $newEnd],
            'today' => FacilityCalendar::today()->toDateString(),
            'days_before' => count($before),
            'days_after' => count($after),
            'days_added' => count($added),
            'days_removed' => count($removed),
            'added_dates' => $added,
            'extends_end' => $extendsEnd,
            'increases_cost' => $added !== [] || $extendsEnd,
            'months' => $months,
            'touches_locked_period' => $touchesLocked,
            'changes_locked_period' => $lockedChanged,
            'locked_periods_changed' => array_values(array_map(fn (array $m): string => $m['period'], array_filter($months, fn (array $m): bool => $m['locked'] && $m['changed']))),
        ];

        if ($withMoney) {
            $beforeCents = array_sum(array_map(fn (array $m): int => (int) ($m['cost_before_cents'] ?? 0), $months));
            $afterCents = array_sum(array_map(fn (array $m): int => (int) ($m['cost_after_cents'] ?? 0), $months));
            $result['cost_before_cents'] = $beforeCents;
            $result['cost_after_cents'] = $afterCents;
            $result['cost_delta_cents'] = $afterCents - $beforeCents;
        }

        return $result;
    }

    /** The cover as it would be with other dates; never saved. */
    public function withDates(TemporaryReplacement $replacement, string $start, ?string $end): TemporaryReplacement
    {
        $clone = $replacement->replicate();
        $clone->id = $replacement->id;
        $clone->start_date = $start;
        $clone->end_date = $end;

        return $clone;
    }

    /** @return list<string> */
    private function days(TemporaryReplacement $replacement): array
    {
        return CoveragePeriod::dates($replacement)['dates'];
    }

    private function cost(TemporaryReplacement $replacement, string $period): ?int
    {
        try {
            return (int) $this->costs->forPeriod($replacement, $period)['amount_cents'];
        } catch (HttpResponseException) {
            return null; // no salary on a covered day: the cost is never guessed
        }
    }
}
