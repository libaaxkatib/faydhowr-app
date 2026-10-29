<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollRun;

/**
 * Read-only checks shown beside a payroll run before it is approved or finalized. They never change a run or its calculation; they only describe
 * what the person preparing and finalizing the payroll should look at once more. (A line twice for the same assignment cannot exist: the database
 * allows one line per assignment and run. Missing data and the other calculation warnings are stored with the run when it is generated.)
 */
final class PayrollRunChecks
{
    /** A month whose total net pay differs from the previous month's by more than this share is worth a second look. */
    public const UNUSUAL_TOTAL_SHARE = 0.25;

    /**
     * @return list<string>
     */
    public static function for(PayrollRun $run): array
    {
        $checks = [];

        $nonPositive = $run->items->filter(fn ($item): bool => (int) $item->net_cents <= 0);

        if ($nonPositive->isNotEmpty()) {
            $ids = $nonPositive->take(5)->map(fn ($item): string => '#'.$item->work_assignment_id)->implode(', ');
            $more = $nonPositive->count() > 5 ? ' and '.($nonPositive->count() - 5).' more' : '';
            $checks[] = $nonPositive->count().' payroll line(s) have a net amount of zero or less (assignment '.$ids.$more.'). Check the deductions on these lines before approving.';
        }

        $net = (int) ($run->totals['net_cents'] ?? 0);
        $previous = PayrollRun::query()
            ->where('period', '<', $run->period)
            ->orderByDesc('period')
            ->get()
            ->first(fn (PayrollRun $other): bool => (int) ($other->totals['net_cents'] ?? 0) > 0);

        if ($previous !== null && $net > 0) {
            $before = (int) $previous->totals['net_cents'];
            $change = ($net - $before) / $before;

            if (abs($change) > self::UNUSUAL_TOTAL_SHARE) {
                $checks[] = sprintf(
                    'The total net pay of %s (%s USD) is %d%% %s than %s (%s USD). Check that this is expected before approving.',
                    $run->period,
                    PayrollMoney::format($net),
                    (int) round(abs($change) * 100),
                    $change > 0 ? 'higher' : 'lower',
                    $previous->period,
                    PayrollMoney::format($before),
                );
            }
        }

        return $checks;
    }
}
