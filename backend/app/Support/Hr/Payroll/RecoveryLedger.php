<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollCorrection;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRecoveryDeduction;
use App\Models\PayrollRun;

/**
 * The ledger of overpayment recoveries. The deductions themselves live on the payroll lines (so a month's pay is reproducible);
 * this keeps one row per correction and month - unique in the database - and the running balance:
 *
 *   scheduled  the month's draft/reviewed run contains the deduction; it can still be regenerated
 *   applied    the month is approved/finalized, so the deduction counts as recovered
 *
 * Regenerating a draft replaces its rows (never adds), approving only flips their status, so retries cannot deduct twice.
 */
final class RecoveryLedger
{
    /** Rebuilds the ledger rows of a run from the recovery deductions on its lines (called after a draft is generated). */
    public static function syncFromRun(PayrollRun $run): void
    {
        $touched = PayrollRecoveryDeduction::query()->where('payroll_run_id', $run->id)->pluck('payroll_correction_id')->all();
        PayrollRecoveryDeduction::query()->where('payroll_run_id', $run->id)->where('status', PayrollRecoveryDeduction::STATUS_SCHEDULED)->delete();

        $perCorrection = [];

        foreach ($run->items()->with('deductions')->get() as $item) {
            foreach ($item->deductions->where('kind', PayrollItemDeduction::KIND_OVERPAYMENT_RECOVERY) as $deduction) {
                $perCorrection[$deduction->source_id] = ($perCorrection[$deduction->source_id] ?? 0) + (int) $deduction->amount_cents;
            }
        }

        foreach ($perCorrection as $correctionId => $cents) {
            PayrollRecoveryDeduction::query()->updateOrCreate(
                ['payroll_correction_id' => $correctionId, 'period' => $run->period],
                ['payroll_run_id' => $run->id, 'amount_cents' => $cents, 'status' => PayrollRecoveryDeduction::STATUS_SCHEDULED],
            );
        }

        self::refresh([...$touched, ...array_keys($perCorrection)]);
    }

    /** An approved month's recoveries count as recovered. */
    public static function markApplied(PayrollRun $run): void
    {
        self::setStatus($run, PayrollRecoveryDeduction::STATUS_APPLIED);
    }

    /** A reopened month's recoveries are scheduled again (it will be regenerated). */
    public static function markScheduled(PayrollRun $run): void
    {
        self::setStatus($run, PayrollRecoveryDeduction::STATUS_SCHEDULED);
    }

    private static function setStatus(PayrollRun $run, string $status): void
    {
        $ids = PayrollRecoveryDeduction::query()->where('payroll_run_id', $run->id)->pluck('payroll_correction_id')->all();
        PayrollRecoveryDeduction::query()->where('payroll_run_id', $run->id)->update(['status' => $status]);
        self::refresh($ids);
    }

    /** @param  list<int>  $correctionIds */
    private static function refresh(array $correctionIds): void
    {
        foreach (PayrollCorrection::query()->whereIn('id', array_values(array_unique($correctionIds)))->get() as $correction) {
            $correction->refreshBalance();
        }
    }
}
