<?php

namespace App\Support\Hr\Payroll;

use App\Enums\TemporaryReplacementStatus;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use RuntimeException;

/**
 * The amount a Temporary employee is EXPECTED to be paid for a cover (docs/HR_PAYROLL_RULES.md N5, N6, N9, N10, N11).
 *
 * Unlike the cost deducted from the Permanent's pay (ReplacementCostCalculator, A6: verified workdays only), the expected PAYMENT counts every calendar
 * day on which the cover actually existed, whether or not each day was individually verified:
 *
 *   - the window is the cover's start date up to its ACTUAL last day (end_date: an early termination wins over the planned date, and nothing after
 *     it is counted), bounded by the covered assignment's own dates, and never later than the day of the calculation (nothing is paid in advance);
 *   - for every calendar day: the covered Permanent's monthly salary IN EFFECT ON THAT DAY / the real number of days of that month (28, 29, 30, 31);
 *     a salary change therefore splits the month by its effective date (N6);
 *   - each month is rounded once, half-up, from integer cents (A4), and the months are added;
 *   - a day is never counted twice: when another cover of the same position overlaps, the day belongs to the cover that started first (then the lower
 *     id) and is listed as an overlap warning here.
 *
 * Example (N11): salary 600, 10 eligible days of a 30-day month = 600 x 10 / 30 = 200.00.
 *
 * Nothing here approves anything: `approved` reports whether the cover's cost was approved, and the verified-days cost is returned beside the
 * expected payment so a difference is visible. It writes nothing.
 */
final class TemporaryExpectedPayment
{
    public function __construct(private readonly ReplacementCostService $costs = new ReplacementCostService) {}

    /**
     * @return array<string, mixed>
     */
    public function forReplacement(TemporaryReplacement $replacement, ?CarbonImmutable $through = null): array
    {
        $assignment = $replacement->workAssignment()->firstOrFail();

        try {
            $salary = new SalaryTimeline($assignment->monthlySalaryPeriods());
        } catch (RuntimeException $e) {
            throw new HttpResponseException(ApiResponse::error($e->getMessage(), 'PAYROLL_UNSUPPORTED_SALARY', 422));
        }

        // Plain calendar dates throughout: the business day is Mogadishu's, but every comparison below is date against date (all at UTC midnight),
        // never an instant in another zone against a date.
        $today = CarbonImmutable::parse(FacilityCalendar::today()->toDateString())->startOfDay();
        $through = CarbonImmutable::parse(($through ?? $today)->toDateString())->startOfDay();
        $through = $through->gt($today) ? $today : $through;

        $start = CarbonImmutable::parse($replacement->start_date)->startOfDay();
        $assignmentStart = CarbonImmutable::parse($assignment->start_date)->startOfDay();
        $start = $start->lt($assignmentStart) ? $assignmentStart : $start;

        $last = $through;

        if ($replacement->end_date !== null) {
            $actualEnd = CarbonImmutable::parse($replacement->end_date)->startOfDay();
            $last = $actualEnd->lt($last) ? $actualEnd : $last;
        }

        if ($assignment->end_date !== null) {
            $assignmentEnd = CarbonImmutable::parse($assignment->end_date)->startOfDay();
            $last = $assignmentEnd->lt($last) ? $assignmentEnd : $last;
        }

        $overlaps = $this->overlappedDays($replacement, $start, $last);
        $months = [];

        for ($day = $start; $day->lte($last); $day = $day->addDay()) {
            $iso = $day->toDateString();

            if (isset($overlaps['days'][$iso])) {
                continue;
            }

            $monthly = $salary->monthlyCentsOn($day);

            if ($monthly === null) {
                throw new HttpResponseException(ApiResponse::error("No permanent salary is defined for {$iso}; the expected payment will not be guessed.", 'EXPECTED_PAYMENT_NO_SALARY', 422));
            }

            $period = $day->format('Y-m');
            $months[$period] ??= ['period' => $period, 'days_in_month' => (int) $day->format('t'), 'days' => 0, 'numerator' => 0, 'segments' => []];
            $months[$period]['days']++;
            $months[$period]['numerator'] += $monthly;

            $lastSegment = array_key_last($months[$period]['segments']);

            if ($lastSegment !== null && $months[$period]['segments'][$lastSegment]['monthly_cents'] === $monthly && $months[$period]['segments'][$lastSegment]['to'] === $day->subDay()->toDateString()) {
                $months[$period]['segments'][$lastSegment]['to'] = $iso;
                $months[$period]['segments'][$lastSegment]['days']++;
            } else {
                $months[$period]['segments'][] = ['from' => $iso, 'to' => $iso, 'days' => 1, 'monthly_cents' => $monthly];
            }
        }

        $totalCents = 0;
        $verifiedCostCents = 0;
        $monthRows = [];

        foreach ($months as $period => $month) {
            $amount = PayrollMoney::roundDiv($month['numerator'], $month['days_in_month']);
            $totalCents += $amount;
            $verifiedCostCents += (int) $this->costs->forPeriod($replacement, (string) $period)['amount_cents'];
            $monthRows[] = [
                'period' => (string) $period,
                'days_in_month' => $month['days_in_month'],
                'days' => $month['days'],
                'segments' => $month['segments'],
                'amount_cents' => $amount,
                'amount' => PayrollMoney::format($amount),
            ];
        }

        $planned = $replacement->planned_end_date?->toDateString();
        $actual = $replacement->end_date?->toDateString();
        $warnings = [];

        if ($replacement->approved_at === null) {
            $warnings[] = ['code' => 'COST_NOT_APPROVED', 'message' => 'The cost of this cover has not been approved yet. The expected payment is a calculation, not an approval.'];
        }

        if ($overlaps['days'] !== []) {
            $warnings[] = ['code' => 'OVERLAPPING_COVER', 'message' => count($overlaps['days']).' day(s) are also covered by an earlier cover of the same position and are counted only for that cover.'];
        }

        if ($totalCents !== $verifiedCostCents) {
            $warnings[] = ['code' => 'DIFFERS_FROM_VERIFIED_COST', 'message' => 'The expected payment counts every day of the cover; the cost deducted from the Permanent counts only verified workdays ('.PayrollMoney::format($verifiedCostCents).').'];
        }

        return [
            'replacement_id' => $replacement->id,
            'currency' => PayrollMoney::CURRENCY,
            'from' => $last->lt($start) ? null : $start->toDateString(),
            'through' => $last->lt($start) ? null : $last->toDateString(),
            'counted_days' => array_sum(array_column($monthRows, 'days')),
            'planned_end_date' => $planned,
            'actual_end_date' => $actual,
            'ended_early' => $planned !== null && $actual !== null && $actual < $planned,
            'status' => $replacement->status instanceof TemporaryReplacementStatus ? $replacement->status->value : (string) $replacement->status,
            'months' => $monthRows,
            'expected_cents' => $totalCents,
            'expected' => PayrollMoney::format($totalCents),
            'verified_days_cost_cents' => $verifiedCostCents,
            'verified_days_cost' => PayrollMoney::format($verifiedCostCents),
            'approved' => $replacement->approved_at !== null,
            'overlap' => ['days' => count($overlaps['days']), 'with' => $overlaps['with']],
            'warnings' => $warnings,
        ];
    }

    /**
     * Days of [$from, $to] that an earlier cover of the SAME covered assignment already owns (earlier start, then lower id).
     *
     * @return array{days: array<string, true>, with: list<int>}
     */
    private function overlappedDays(TemporaryReplacement $replacement, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $with = [];

        if ($to->lt($from)) {
            return ['days' => $days, 'with' => $with];
        }

        $others = TemporaryReplacement::query()
            ->where('work_assignment_id', $replacement->work_assignment_id)
            ->where('id', '!=', $replacement->id)
            ->get()
            ->filter(fn (TemporaryReplacement $o): bool => $o->start_date->lt($replacement->start_date) || ($o->start_date->equalTo($replacement->start_date) && $o->id < $replacement->id));

        foreach ($others as $other) {
            $otherStart = CarbonImmutable::parse($other->start_date)->startOfDay();
            $otherEnd = $other->end_date === null ? $to : CarbonImmutable::parse($other->end_date)->startOfDay();
            $lo = $otherStart->gt($from) ? $otherStart : $from;
            $hi = $otherEnd->lt($to) ? $otherEnd : $to;

            for ($day = $lo; $day->lte($hi); $day = $day->addDay()) {
                $days[$day->toDateString()] = true;
                $with[$other->id] = $other->id;
            }
        }

        return ['days' => $days, 'with' => array_values($with)];
    }
}
