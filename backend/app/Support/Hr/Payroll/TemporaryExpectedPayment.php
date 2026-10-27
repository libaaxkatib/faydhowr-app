<?php

namespace App\Support\Hr\Payroll;

use App\Enums\TemporaryReplacementStatus;
use App\Models\TemporaryReplacement;
use App\Support\ApiResponse;
use App\Support\Hr\CoverConflicts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use RuntimeException;

/**
 * The Temporary employee's ENTITLEMENT for a cover and the deduction it causes on the covered Permanent (docs/HR_PAYROLL_RULES.md U8, N5, N6, N9-N11).
 *
 * Both are worked out from the same actual coverage period (CoveragePeriod): every calendar day from the cover's actual start to its actual last
 * day, both inclusive, the covered Permanent's salary in effect on each day over the real number of days of that month (28, 29, 30, 31), each month
 * rounded once, half-up. Verified attendance days play no part. The Temporary's entitlement is calculated first; the deduction from the Permanent
 * is then determined by the existing approved rule (ReplacementCostService): the entitlement of the month is deducted once, only after the cover is
 * approved, and a day the Permanent was already on unpaid leave is not deducted twice. There is no separate penalty formula.
 *
 * Example (N11): salary 600, 10 eligible days of a 30-day month = 600 x 10 / 30 = 200.00.
 *
 * Nothing here approves anything: `approved` reports whether the cover was approved for deduction. It writes nothing.
 */
final class TemporaryExpectedPayment
{
    public function __construct(private readonly ReplacementCostService $costs = new ReplacementCostService) {}

    /**
     * @return array<string, mixed>
     */
    public function forReplacement(TemporaryReplacement $replacement, ?CarbonImmutable $through = null): array
    {
        try {
            $coverage = CoveragePeriod::resolve($replacement, $through);
        } catch (RuntimeException $e) {
            throw new HttpResponseException(ApiResponse::error($e->getMessage(), 'EXPECTED_PAYMENT_NO_SALARY', 422));
        }

        $months = [];

        foreach ($coverage['days'] as $iso => $monthly) {
            $day = CarbonImmutable::parse($iso);
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
        $deductionCents = 0;
        $monthRows = [];

        foreach ($months as $period => $month) {
            $entitlement = PayrollMoney::roundDiv($month['numerator'], $month['days_in_month']);
            $deduction = (int) $this->costs->forPeriod($replacement, (string) $period)['amount_cents'];
            $totalCents += $entitlement;
            $deductionCents += $deduction;
            $monthRows[] = [
                'period' => (string) $period,
                'days_in_month' => $month['days_in_month'],
                'days' => $month['days'],
                'segments' => $month['segments'],
                'amount_cents' => $entitlement,
                'amount' => PayrollMoney::format($entitlement),
                'deduction_cents' => $deduction,
                'deduction' => PayrollMoney::format($deduction),
            ];
        }

        $planned = $replacement->planned_end_date?->toDateString();
        $actual = $replacement->end_date?->toDateString();
        $warnings = [];

        if ($replacement->approved_at === null) {
            $warnings[] = ['code' => 'COST_NOT_APPROVED', 'message' => 'This cover has not been approved for deduction yet. The expected payment is a calculation, not an approval.'];
        }

        // Overlapping covers are refused when saved (U12), so none is expected. Should one exist from before the rule, nothing is silently shared out
        // between them: both are counted in full and HR is told, with the other record, so it can be corrected.
        $overlap = CoverConflicts::find($replacement->workAssignment()->firstOrFail(), $replacement->replacementEmployee()->firstOrFail(), $replacement->start_date->toDateString(), $replacement->end_date?->toDateString(), $replacement->id);

        if ($overlap !== null) {
            $warnings[] = ['code' => 'OVERLAPPING_COVER', 'message' => $overlap['message']];
        }

        if ($totalCents !== $deductionCents) {
            $warnings[] = ['code' => 'DEDUCTION_DIFFERS', 'message' => 'The deduction from the Permanent ('.PayrollMoney::format($deductionCents).') is smaller than the entitlement: days that were already unpaid leave for the covered employee are not deducted twice.'];
        }

        return [
            'replacement_id' => $replacement->id,
            'currency' => PayrollMoney::CURRENCY,
            'from' => $coverage['empty'] ? null : $coverage['start']->toDateString(),
            'through' => $coverage['empty'] ? null : $coverage['last']->toDateString(),
            'counted_days' => array_sum(array_column($monthRows, 'days')),
            'planned_end_date' => $planned,
            'actual_end_date' => $actual,
            'ended_early' => $planned !== null && $actual !== null && $actual < $planned,
            'status' => $replacement->status instanceof TemporaryReplacementStatus ? $replacement->status->value : (string) $replacement->status,
            'months' => $monthRows,
            'expected_cents' => $totalCents,
            'expected' => PayrollMoney::format($totalCents),
            'permanent_deduction_cents' => $deductionCents,
            'permanent_deduction' => PayrollMoney::format($deductionCents),
            'approved' => $replacement->approved_at !== null,
            'overlap' => $overlap === null ? null : ['code' => $overlap['code'], 'with' => $overlap['conflicts']],
            'warnings' => $warnings,
        ];
    }
}
