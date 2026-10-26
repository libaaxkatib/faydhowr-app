<?php

namespace App\Support\Hr\Payroll;

use App\Enums\LeaveType;
use App\Models\EmployeeLeave;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementWorkday;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The deduction a Temporary cover causes on the covered Permanent for one month (docs/HR_PAYROLL_RULES.md U8, A6).
 *
 * The days that count are the cover's ACTUAL coverage period (CoveragePeriod): every calendar day from its actual start to its actual last day,
 * both inclusive - the very days the Temporary's entitlement is calculated on. Verified attendance days (the workday ledger) do not decide the
 * amount; they stay a record of attendance and are reported as `verified_days` for information only.
 *
 * The existing approved deduction rule is applied by the pure ReplacementCostCalculator and by the payroll run: the month's entitlement is
 * deducted once, only after the cover is approved, a day outside the covered assignment is not charged, and a day the covered employee was already
 * on approved unpaid leave is not deducted a second time. There is no separate penalty formula.
 */
class ReplacementCostService
{
    public function __construct(private readonly ReplacementCostCalculator $calculator = new ReplacementCostCalculator) {}

    /**
     * Keys of the calculator result plus: covered_days (days charged), entitlement_cents (the Temporary's entitlement for the month before the
     * unpaid-leave rule), verified_days (ledger attendance, informational), coverage (the period used, planned and actual end).
     *
     * @return array<string, mixed>
     */
    public function forPeriod(TemporaryReplacement $replacement, string $period): array
    {
        $assignment = $replacement->workAssignment()->firstOrFail();
        [$first, $daysInMonth] = ProrationCalculator::month($period);
        $monthEnd = CarbonImmutable::parse($first->format('Y-m-d'))->addDays($daysInMonth - 1);

        try {
            $salary = new SalaryTimeline($assignment->monthlySalaryPeriods());
            $coverage = CoveragePeriod::resolve($replacement, $monthEnd);
        } catch (\RuntimeException $e) {
            throw new HttpResponseException(ApiResponse::error($e->getMessage(), 'PAYROLL_UNSUPPORTED_SALARY', 422));
        }

        $inMonth = array_filter($coverage['days'], fn (string $iso): bool => str_starts_with($iso, $period.'-'), ARRAY_FILTER_USE_KEY);
        $numerator = array_sum($inMonth);
        // Declared days of the cover that the covered assignment's own dates cut off (or that an earlier cover owns) are not counted; say which.
        $declared = array_filter(CoveragePeriod::declaredDates($replacement, $monthEnd), fn (string $iso): bool => str_starts_with($iso, $period.'-'));
        $cut = array_values(array_filter($declared, fn (string $iso): bool => ! isset($inMonth[$iso]) && ! isset($coverage['overlap_days'][$iso])));

        $result = $this->calculator->monthlyCost($period, $salary, [
            'replacement_id' => $replacement->id,
            'start_date' => $replacement->start_date->toDateString(),
            'end_date' => $replacement->end_date?->toDateString(),
            'assignment_start' => $assignment->start_date->toDateString(),
            'assignment_end' => $assignment->end_date?->toDateString(),
            'unpaid_leave_dates' => $this->approvedUnpaidLeaveDates($assignment->employee_id, $period),
            'workdays' => array_map(fn (string $iso): array => ['date' => $iso, 'verified' => true], array_keys($inMonth)),
        ]);

        $result['not_chargeable']['outside_assignment'] = array_values(array_unique([...$result['not_chargeable']['outside_assignment'], ...$cut]));
        sort($result['not_chargeable']['outside_assignment']);

        // The calculator calls the days it charged `verified_days`; here they are the covered days, and `verified_days` becomes the ledger's attendance count.
        return array_merge($result, [
            'covered_days' => $result['verified_days'],
            'entitlement_cents' => PayrollMoney::roundDiv($numerator, $daysInMonth),
            'verified_days' => $replacement->workdays()
                ->where('status', TemporaryReplacementWorkday::STATUS_VERIFIED)
                ->whereDate('work_date', '>=', $first->format('Y-m-d'))
                ->whereDate('work_date', '<=', $monthEnd->toDateString())
                ->count(),
            'coverage' => [
                'basis' => 'coverage_period',
                'from' => $coverage['empty'] ? null : $coverage['start']->toDateString(),
                'through' => $coverage['empty'] ? null : $coverage['last']->toDateString(),
                'planned_end_date' => $replacement->planned_end_date?->toDateString(),
                'actual_end_date' => $replacement->end_date?->toDateString(),
                'overlap_days' => count($coverage['overlap_days']),
            ],
        ]);
    }

    /**
     * Calendar days of the month covered by APPROVED unpaid leave. Those days
     * are already unpaid, so the replacement cost is not charged again.
     *
     * @return list<string>
     */
    private function approvedUnpaidLeaveDates(int $employeeId, string $period): array
    {
        [$first, $daysInMonth] = ProrationCalculator::month($period);
        $last = $first->modify('+'.($daysInMonth - 1).' days');
        $dates = [];

        $leaves = EmployeeLeave::query()
            ->where('employee_id', $employeeId)
            ->where('leave_type', LeaveType::Unpaid)
            ->where('approval_status', 'approved')
            ->whereDate('start_date', '<=', $last->format('Y-m-d'))
            ->whereDate('end_date', '>=', $first->format('Y-m-d'))
            ->get();

        foreach ($leaves as $leave) {
            $from = max($leave->start_date->toDateString(), $first->format('Y-m-d'));
            $to = min($leave->end_date->toDateString(), $last->format('Y-m-d'));

            for ($day = new \DateTimeImmutable($from); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
                $dates[$day->format('Y-m-d')] = true;
            }
        }

        return array_keys($dates);
    }
}
