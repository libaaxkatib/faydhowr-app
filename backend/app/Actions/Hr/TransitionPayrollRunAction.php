<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollSections;
use App\Support\Hr\Payroll\RecoveryLedger;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Moves a payroll run through draft -> reviewed (and back to draft by reopening a reviewed one). There is NO Approve (approval is payment, PayrollApproval) and finalizing is NOT done here: it is only
 * done company by company (TransitionPayrollRunSectionAction), and the month finalizes itself when the last company is finalized.
 * Each step must start from the exact previous state. Owner decision (2026-10-29): the authorized HR Manager prepares, reviews, approves and
 * finalizes payroll; a second person is NOT required, so the same person may do every step. Who did each step and when is stored on the run and in
 * the audit log. An approved run can be reopened to draft (with a reason) for correction; a finalized run can never be reopened - corrections
 * are payroll adjustments. Paying is a separate action on each line.
 */
class TransitionPayrollRunAction
{
    /**
     * A run with company sections (see TransitionPayrollRunSectionAction) follows them: these month-level actions move EVERY section that is in the
     * previous state and leave the others as they are, and the month's own status is the least advanced section's. A run without sections (created
     * before company-level finalization) is moved as a whole, exactly as before.
     */
    private function bulk(PayrollRun $run, string|array $from, string $to, Admin $actor, string $word, ?string $reason = null): ?PayrollRun
    {
        if (! $run->sections()->exists()) {
            return null;
        }

        if ($to === PayrollRun::STATUS_REVIEWED && ! $run->items()->exists()) {
            $this->fail('A payroll run with no lines cannot be reviewed.', 'PAYROLL_RUN_EMPTY');
        }

        // The month-level step, like the company-level one, starts from the exact previous state - here the MONTH's (its least advanced company).
        // Reopening is different: it moves every company that is reviewed (the states before finalizing) back to draft, so it needs only one such company.
        if ($to !== PayrollRun::STATUS_DRAFT && $run->status !== $from) {
            $this->require($run, $from, $word);
        }

        if ($to === PayrollRun::STATUS_DRAFT && ! $run->sections()->whereIn('status', (array) $from)->exists()) {
            $this->fail("Only a reviewed payroll can be reopened (this one is {$run->status}). Finalized payroll is corrected with adjustments.", 'PAYROLL_RUN_NOT_REOPENABLE');
        }

        $moved = app(TransitionPayrollRunSectionAction::class)->bulk($run, $from, $to, $actor, $reason);

        // Nothing to move: the month's status is brought in line with its companies' (it is the least advanced one).
        if ($moved === 0) {
            PayrollSections::syncRun($run->refresh());
        }

        $run->refresh();
        $description = match ($word) {
            'reviewed' => "Payroll {$run->period} marked reviewed.",
            'approved' => "Payroll {$run->period} approved.",
            default => "Payroll {$run->period} reopened to draft.",
        };

        return $this->audited($run, $actor, $to === PayrollRun::STATUS_APPROVED ? AuditAction::Approve : AuditAction::Update, $description, array_filter(['sections_moved' => $moved, 'month_status' => $run->status, 'reason' => $reason], fn ($v) => $v !== null));
    }

    public function review(PayrollRun $run, Admin $actor): PayrollRun
    {
        if (($bulk = $this->bulk($run, PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_REVIEWED, $actor, 'reviewed')) !== null) {
            return $bulk;
        }

        $this->require($run, PayrollRun::STATUS_DRAFT, 'reviewed');

        if (! $run->items()->exists()) {
            $this->fail('A payroll run with no lines cannot be reviewed.', 'PAYROLL_RUN_EMPTY');
        }

        $run->update(['status' => PayrollRun::STATUS_REVIEWED, 'reviewed_at' => now(), 'reviewed_by' => $actor->id]);

        return $this->audited($run, $actor, AuditAction::Update, "Payroll {$run->period} marked reviewed.");
    }

    // There is NO month-level Approve (owner decision 2026-11-04): the month is approved by itself when every company has been paid in full (PayrollApproval).

    public function reopen(PayrollRun $run, string $reason, Admin $actor): PayrollRun
    {
        $before = [PayrollRun::STATUS_REVIEWED, PayrollRun::STATUS_APPROVED];

        if (($bulk = $this->bulk($run, $before, PayrollRun::STATUS_DRAFT, $actor, 'reopened', $reason)) !== null) {
            return $bulk;
        }

        if (! in_array($run->status, $before, true)) {
            $this->fail("Only a reviewed payroll can be reopened (this one is {$run->status}). Finalized payroll is corrected with adjustments.", 'PAYROLL_RUN_NOT_REOPENABLE');
        }

        $run->update(['status' => PayrollRun::STATUS_DRAFT, 'reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null]);
        RecoveryLedger::markScheduled($run);

        return $this->audited($run, $actor, AuditAction::Update, "Payroll {$run->period} reopened to draft.", ['reason' => $reason]);
    }

    // There is NO month-level finalize (owner decision 2026-11-03): a company is finalized only through its own section
    // (TransitionPayrollRunSectionAction::finalize), and the month finalizes itself when the last company is finalized.

    private function require(PayrollRun $run, string $expected, string $target): void
    {
        if ($run->status !== $expected) {
            $this->fail("Payroll {$run->period} is {$run->status}; it must be {$expected} before it can be {$target}.", 'PAYROLL_RUN_WRONG_STATE');
        }
    }

    private function audited(PayrollRun $run, Admin $actor, AuditAction $action, string $description, ?array $metadata = null): PayrollRun
    {
        event(AuditEvent::record(action: $action, admin: $actor, description: $description, entityType: PayrollRun::class, entityId: $run->id, metadata: $metadata));

        return $run->refresh();
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
