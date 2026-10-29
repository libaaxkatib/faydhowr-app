<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\RecoveryLedger;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Moves a payroll run through draft -> reviewed -> approved -> finalized.
 * Each step must start from the exact previous state. Owner decision (2026-10-29): the authorized HR Manager prepares, reviews, approves and
 * finalizes payroll; a second person is NOT required, so the same person may do every step. Who did each step and when is stored on the run and in
 * the audit log. An approved run can be reopened to draft (with a reason) for correction; a finalized run can never be reopened - corrections
 * are payroll adjustments. Paying is a separate action on each line.
 */
class TransitionPayrollRunAction
{
    public function review(PayrollRun $run, Admin $actor): PayrollRun
    {
        $this->require($run, PayrollRun::STATUS_DRAFT, 'reviewed');

        if (! $run->items()->exists()) {
            $this->fail('A payroll run with no lines cannot be reviewed.', 'PAYROLL_RUN_EMPTY');
        }

        $run->update(['status' => PayrollRun::STATUS_REVIEWED, 'reviewed_at' => now(), 'reviewed_by' => $actor->id]);

        return $this->audited($run, $actor, AuditAction::Update, "Payroll {$run->period} marked reviewed.");
    }

    public function approve(PayrollRun $run, Admin $actor): PayrollRun
    {
        $this->require($run, PayrollRun::STATUS_REVIEWED, 'approved');

        $run->update(['status' => PayrollRun::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => $actor->id]);
        RecoveryLedger::markApplied($run);

        return $this->audited($run, $actor, AuditAction::Approve, "Payroll {$run->period} approved.");
    }

    public function reopen(PayrollRun $run, string $reason, Admin $actor): PayrollRun
    {
        if ($run->status !== PayrollRun::STATUS_APPROVED) {
            $this->fail("Only an approved payroll can be reopened (this one is {$run->status}). Finalized payroll is corrected with adjustments.", 'PAYROLL_RUN_NOT_REOPENABLE');
        }

        $run->update(['status' => PayrollRun::STATUS_DRAFT, 'reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null]);
        RecoveryLedger::markScheduled($run);

        return $this->audited($run, $actor, AuditAction::Update, "Payroll {$run->period} reopened to draft.", ['reason' => $reason]);
    }

    public function finalize(PayrollRun $run, Admin $actor): PayrollRun
    {
        $this->require($run, PayrollRun::STATUS_APPROVED, 'finalized');

        $run->update(['status' => PayrollRun::STATUS_FINALIZED, 'finalized_at' => now(), 'finalized_by' => $actor->id]);

        return $this->audited($run, $actor, AuditAction::Approve, "Payroll {$run->period} finalized.", ['totals' => $run->totals]);
    }

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
