<?php

namespace App\Support\Hr\Payroll;

/**
 * The one place that keeps a Permanent employee's net pay from going below zero because of Temporary covers (owner decision 2026-11-03):
 *
 *   Permanent net = max(0, gross - Temporary replacement deduction)        (when the line has no other deductions)
 *
 * Only what is TAKEN OFF the Permanent's line is capped. The Temporary's own calculated rate and payable amount (TemporaryRate: salary / 30 x days)
 * are never changed: a Permanent of 300.00 covered for 31 days costs 310.00, the Temporary is still owed 310.00, and 300.00 is deducted - the net is 0.00.
 *
 * What a line can still give to covers is its gross less the line's OTHER deductions (penalties, advances, absence, unpaid leave, recovery) plus pending
 * adjustments, never below zero. The other deductions are not changed by this rule; the cover deduction only takes what is left, so a cover can never be
 * the reason for a negative net. Several covers share what is left in order (the order they are given), each cover first filling its own cost.
 *
 * PayrollRunCalculator (every payroll run, and through it the workbook, the payment list, its preview and its download) and PayrollSheetBuilder both
 * call this class, so there is no second copy of the rule.
 */
final class ReplacementDeductionCap
{
    /** What the Temporary covers may still take off a line: gross - other deductions + pending adjustments, never below zero. */
    public static function available(int $grossCents, int $otherDeductionCents, int $adjustmentCents = 0): int
    {
        return max(0, $grossCents - $otherDeductionCents + $adjustmentCents);
    }

    /**
     * The amount actually deducted for each cover, in the order given: each cover's own cost while there is something left, then what is left, then 0.
     *
     * @param  list<int>  $costsCents  what each cover costs (the Temporary's payable amount, unchanged)
     * @return list<int>
     */
    public static function allocate(int $availableCents, array $costsCents): array
    {
        $left = max(0, $availableCents);
        $applied = [];

        foreach ($costsCents as $cost) {
            $take = min(max(0, $cost), $left);
            $applied[] = $take;
            $left -= $take;
        }

        return $applied;
    }
}
