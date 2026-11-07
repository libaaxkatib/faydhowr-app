<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\PayrollSectionReopening;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\CorrectionSupersession;
use App\Support\Hr\Payroll\DeductionInputsFingerprint;
use App\Support\Hr\Payroll\InputOwnership;
use App\Support\Hr\Payroll\PayrollSections;
use App\Support\Hr\Payroll\PayrollSourceInputs;
use App\Support\Hr\Payroll\RecoveryLedger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Moves ONE company's section of a payroll month through draft -> reviewed -> finalized (a reviewed section can be reopened to draft with a reason; a
 * FINALIZED one only through ReopenFinalizedPayrollSectionAction, which keeps every payment and records the reopening). Each action changes only the section it is given: other companies keep their own status. Same owner rules
 * as the run (2026-10-29): the authorized HR Manager may do every step, a second person is not required; who did what and when is stored on the
 * section and in the audit log (company, period, previous and new status).
 *
 * The month's own status follows its sections (PayrollSections::syncRun): it becomes approved / finalized only when EVERY company is, and then
 * finalizes automatically. There is no month-level action of any kind (no bulk review, finalize or reopen): every change names ONE company.
 */
class TransitionPayrollRunSectionAction
{
    public function review(PayrollRunSection $section, Admin $actor): PayrollRunSection
    {
        return $this->single($section, PayrollRunSection::STATUS_DRAFT, PayrollRunSection::STATUS_REVIEWED, $actor, null, 'reviewed');
    }

    // There is NO approve step (owner decision 2026-11-04): a company is approved by being paid in full (PayrollApproval), never by a button. A company is
    // finalized straight from reviewed. A section that an earlier version left "approved" is still accepted wherever "reviewed" is.

    public function reopen(PayrollRunSection $section, string $reason, Admin $actor): PayrollRunSection
    {
        // A FINALIZED company is reopened by its own action (owner decision 2026-11-04): longer reason, payments kept, a recorded reopening.
        if ($section->status === PayrollRunSection::STATUS_FINALIZED) {
            return app(ReopenFinalizedPayrollSectionAction::class)->handle($section, $reason, $actor);
        }

        return $this->single($section, self::BEFORE_FINAL, PayrollRunSection::STATUS_DRAFT, $actor, $reason, 'reopened');
    }

    public function finalize(PayrollRunSection $section, Admin $actor): PayrollRunSection
    {
        return $this->single($section, self::BEFORE_FINAL, PayrollRunSection::STATUS_FINALIZED, $actor, null, 'finalized');
    }

    // There is NO bulk transition (owner decisions 2026-11-03 for finalize, D-2 for review, O-1 for reopen): the only way to move a section is `single()`, one company at a time.

    /** The states a company may be in before it is finalized or sent back to draft: reviewed (or "approved", which only an older version could leave behind). */
    private const BEFORE_FINAL = [PayrollRunSection::STATUS_REVIEWED, PayrollRunSection::STATUS_APPROVED];

    /** @param  string|list<string>  $from */
    private function single(PayrollRunSection $section, string|array $from, string $to, Admin $actor, ?string $reason, string $word): PayrollRunSection
    {
        return DB::transaction(function () use ($section, $from, $to, $actor, $reason, $word): PayrollRunSection {
            // The run first, then the section: the same order Generate and the payment actions lock in, so they are decided one after the other.
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($section->payroll_run_id);
            $section = PayrollRunSection::query()->lockForUpdate()->findOrFail($section->id);

            if (! in_array($section->status, (array) $from, true)) {
                $name = $section->clientCompany?->name ?? 'this company';
                $this->fail($to === PayrollRunSection::STATUS_DRAFT
                    ? "Only a reviewed company payroll can be reopened ({$name} is {$section->status}). Finalized payroll is corrected with adjustments."
                    : "Payroll {$run->period} for {$name} is {$section->status}; it must be ".implode(' or ', (array) $from)." before it can be {$word}.", $to === PayrollRunSection::STATUS_DRAFT ? 'PAYROLL_RUN_NOT_REOPENABLE' : 'PAYROLL_RUN_WRONG_STATE');
            }

            $this->apply($section, $run, $section->status, $to, $actor, $reason);
            $this->afterChange($run, $actor, true);

            return $section->refresh();
        });
    }

    private function apply(PayrollRunSection $section, PayrollRun $run, string $from, string $to, Admin $actor, ?string $reason): void
    {
        if ($to === PayrollRunSection::STATUS_REVIEWED && ! $section->items()->exists()) {
            $this->fail('A payroll run with no lines cannot be reviewed.', 'PAYROLL_RUN_EMPTY');
        }

        // P1: reopening a company's month makes its open corrections obsolete (refused when money moved for one); finalizing again needs a fresh Generate first.
        if ($to === PayrollRunSection::STATUS_DRAFT) {
            CorrectionSupersession::assertCanSupersede($section, $run->period);
        }

        if ($to === PayrollRunSection::STATUS_FINALIZED) {
            CorrectionSupersession::assertRegenerated($section, $run);
        }

        // Release 1B freshness guard: a penalty / advance registered, corrected, voided or waived since Generate must not be locked in unseen.
        if (in_array($to, [PayrollRunSection::STATUS_REVIEWED, PayrollRunSection::STATUS_APPROVED, PayrollRunSection::STATUS_FINALIZED], true)) {
            // Owner decision O2 (2026-11-07): without a stored snapshot the guards below cannot judge anything, so Review and Finalize refuse; Generate / refresh draft stores it.
            PayrollSourceInputs::assertSnapshotted($section, $run);
            DeductionInputsFingerprint::assertFresh($section, $run);
            // ... and so must an assignment, a salary row or a Temporary cover changed since Generate (PayrollSourceInputs).
            PayrollSourceInputs::assertFresh($section, $run);
        }

        if (in_array($to, [PayrollRunSection::STATUS_APPROVED, PayrollRunSection::STATUS_FINALIZED], true)) {
            $this->assertNoUnownedInputs($section, $run);
        }

        $stamp = match ($to) {
            PayrollRunSection::STATUS_REVIEWED => ['reviewed_at' => now(), 'reviewed_by' => $actor->id],
            PayrollRunSection::STATUS_APPROVED => ['approved_at' => now(), 'approved_by' => $actor->id],
            PayrollRunSection::STATUS_FINALIZED => ['finalized_at' => now(), 'finalized_by' => $actor->id],
            default => ['reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null],
        };

        $section->update(['status' => $to, ...$stamp]);

        if ($to === PayrollRunSection::STATUS_DRAFT) {
            CorrectionSupersession::supersede($section, $run->period, $actor, $reason ?? 'Payroll reopened to draft');
        }

        // Finalizing again after a reopening: the reopening record keeps what the corrected payroll holds now next to what it held before.
        if ($to === PayrollRunSection::STATUS_FINALIZED) {
            PayrollSectionReopening::query()->where('payroll_run_section_id', $section->id)->whereNull('refinalized_at')->orderByDesc('id')->limit(1)
                ->update(['refinalized_at' => now(), 'refinalized_by' => $actor->id, 'totals_after' => json_encode($section->totals)]);
        }

        $name = $section->clientCompany?->name ?? 'No company';
        $verb = match ($to) {
            PayrollRunSection::STATUS_REVIEWED => 'marked reviewed',
            PayrollRunSection::STATUS_APPROVED => 'approved',
            PayrollRunSection::STATUS_FINALIZED => 'finalized',
            default => 'reopened to draft',
        };

        event(AuditEvent::record(
            action: in_array($to, [PayrollRunSection::STATUS_APPROVED, PayrollRunSection::STATUS_FINALIZED], true) ? AuditAction::Approve : AuditAction::Update,
            admin: $actor,
            description: "Payroll {$run->period} for {$name} {$verb}.",
            entityType: PayrollRunSection::class,
            entityId: $section->id,
            metadata: array_filter([
                'event' => 'payroll_section_'.$to,
                'payroll_run_id' => $run->id,
                'period' => $run->period,
                'client_company_id' => $section->client_company_id,
                'company' => $name,
                'previous_status' => $from,
                'new_status' => $to,
                'totals' => $to === PayrollRunSection::STATUS_FINALIZED ? $section->totals : null,
                'reason' => $reason,
            ], fn ($v) => $v !== null),
        ));
    }

    /**
     * Each company owns its deductions, so a company is not locked in while an employee of it has a penalty, advance, leave, absence or recovery that
     * names no company although the employee works for several: the payroll charged it to nobody (nothing is guessed) and approving would make that final.
     */
    private function assertNoUnownedInputs(PayrollRunSection $section, PayrollRun $run): void
    {
        $unowned = InputOwnership::unownedFor($run->period, $section->items()->pluck('employee_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        if ($unowned === []) {
            return;
        }

        $name = $section->clientCompany?->name ?? 'this company';
        $employees = count(array_unique(array_column($unowned, 'employee_id')));

        throw new HttpResponseException(ApiResponse::error(
            count($unowned)." record(s) of {$employees} employee(s) in {$name} name no company although the employee works for several, so no company was charged for them. Assign each one to its company first, then generate again.",
            'PAYROLL_AMBIGUOUS_COMPANY_INPUT',
            422,
            ['unowned_inputs' => array_slice($unowned, 0, 50)],
        ));
    }

    /** The month follows its sections; recoveries follow the lines that carry them; the month finalizes itself when the last company does. */
    private function afterChange(PayrollRun $run, Admin $actor, bool $announce): void
    {
        $before = $run->status;
        $run = PayrollSections::syncRun($run->refresh());
        RecoveryLedger::syncFromRun($run);

        if ($announce && $before !== PayrollRun::STATUS_FINALIZED && $run->status === PayrollRun::STATUS_FINALIZED) {
            event(AuditEvent::record(
                action: AuditAction::Approve,
                admin: $actor,
                description: "Payroll {$run->period} finalized (every company section is finalized).",
                entityType: PayrollRun::class,
                entityId: $run->id,
                metadata: ['event' => 'payroll_run_finalized_by_sections', 'sections' => $run->sections()->count(), 'totals' => $run->totals],
            ));
        }
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
