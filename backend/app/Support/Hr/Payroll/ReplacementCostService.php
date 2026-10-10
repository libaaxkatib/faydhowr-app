<?php

namespace App\Support\Hr\Payroll;

use App\Enums\LeaveType;
use App\Models\EmployeeLeave;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementWorkday;

/**
 * Bridges stored replacements to the pure ReplacementCostCalculator: builds the
 * covered Permanent employee's salary timeline from the assignment's salary
 * history and feeds in the ledger's workdays (verified rows count, recorded
 * rows are listed as unverified, voided rows are ignored).
 */
class ReplacementCostService
{
    public function __construct(private readonly ReplacementCostCalculator $calculator = new ReplacementCostCalculator) {}

    /**
     * @return array{replacement_id: int|string, period: string, days_in_month: int, verified_days: int, charged_dates: list<string>, ignored: array{unverified: int, outside_window_or_month: int, duplicates: int}, not_chargeable: array{outside_assignment: list<string>, on_unpaid_leave: list<string>}, amount_cents: int, daily_rate: string}
     */
    public function forPeriod(TemporaryReplacement $replacement, string $period): array
    {
        $assignment = $replacement->workAssignment()->firstOrFail();
        $salary = new SalaryTimeline($assignment->monthlySalaryPeriods());

        $workdays = $replacement->workdays()
            ->where('status', '!=', TemporaryReplacementWorkday::STATUS_VOID)
            ->get()
            ->map(fn (TemporaryReplacementWorkday $w): array => [
                'date' => $w->work_date->toDateString(),
                'verified' => $w->status === TemporaryReplacementWorkday::STATUS_VERIFIED,
            ])
            ->all();

        return $this->calculator->monthlyCost($period, $salary, [
            'replacement_id' => $replacement->id,
            'start_date' => $replacement->start_date->toDateString(),
            'end_date' => $replacement->end_date?->toDateString(),
            'assignment_start' => $assignment->start_date->toDateString(),
            'assignment_end' => $assignment->end_date?->toDateString(),
            'unpaid_leave_dates' => $this->approvedUnpaidLeaveDates($assignment->employee_id, $period),
            'workdays' => $workdays,
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
