<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollSections;
use App\Support\Hr\Payroll\RecoveryLedger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Moves ONE company's section of a payroll month through draft -> reviewed -> approved -> finalized (an approved section can be reopened to draft,
 * with a reason; a finalized one never). Each action changes only the section it is given: other companies keep their own status. Same owner rules
 * as the run (2026-10-29): the authorized HR Manager may do every step, a second person is not required; who did what and when is stored on the
 * section and in the audit log (company, period, previous and new status).
 *
 * The month's own status follows its sections (PayrollSections::syncRun): it becomes approved / finalized only when EVERY company is, and then
 * finalizes automatically. The month-level buttons use bulk(): every section that is in the previous state moves, the others are left alone.
 */
class TransitionPayrollRunSectionAction
{
    public function review(PayrollRunSection $section, Admin $actor): PayrollRunSection
    {
        return $this->single($section, PayrollRunSection::STATUS_DRAFT, PayrollRunSection::STATUS_REVIEWED, $actor, null, 'reviewed');
    }

    public function approve(PayrollRunSection $section, Admin $actor): PayrollRunSection
    {
        return $this->single($section, PayrollRunSection::STATUS_REVIEWED, PayrollRunSection::STATUS_APPROVED, $actor, null, 'approved');
    }

    public function reopen(PayrollRunSection $section, string $reason, Admin $actor): PayrollRunSection
    {
        return $this->single($section, PayrollRunSection::STATUS_APPROVED, PayrollRunSection::STATUS_DRAFT, $actor, $reason, 'reopened');
    }

    public function finalize(PayrollRunSection $section, Admin $actor): PayrollRunSection
    {
        return $this->single($section, PayrollRunSection::STATUS_APPROVED, PayrollRunSection::STATUS_FINALIZED, $actor, null, 'finalized');
    }

    /**
     * The month-level action: every section that is in $from moves to $to. Returns how many moved (zero when none was in $from).
     */
    public function bulk(PayrollRun $run, string $from, string $to, Admin $actor, ?string $reason = null): int
    {
        return DB::transaction(function () use ($run, $from, $to, $actor, $reason): int {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            $sections = $run->sections()->where('status', $from)->lockForUpdate()->get();

            foreach ($sections as $section) {
                $this->apply($section, $run, $from, $to, $actor, $reason);
            }

            // The month-level caller (TransitionPayrollRunAction) writes the run's own audit entry.
            if ($sections->isNotEmpty()) {
                $this->afterChange($run, $actor, false);
            }

            return $sections->count();
        });
    }

    private function single(PayrollRunSection $section, string $from, string $to, Admin $actor, ?string $reason, string $word): PayrollRunSection
    {
        return DB::transaction(function () use ($section, $from, $to, $actor, $reason, $word): PayrollRunSection {
            // The run first, then the section: the same order Generate and the payment actions lock in, so they are decided one after the other.
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($section->payroll_run_id);
            $section = PayrollRunSection::query()->lockForUpdate()->findOrFail($section->id);

            if ($section->status !== $from) {
                $name = $section->clientCompany?->name ?? 'this company';
                $this->fail($to === PayrollRunSection::STATUS_DRAFT
                    ? "Only an approved company payroll can be reopened ({$name} is {$section->status}). Finalized payroll is corrected with adjustments."
                    : "Payroll {$run->period} for {$name} is {$section->status}; it must be {$from} before it can be {$word}.", $to === PayrollRunSection::STATUS_DRAFT ? 'PAYROLL_RUN_NOT_REOPENABLE' : 'PAYROLL_RUN_WRONG_STATE');
            }

            $this->apply($section, $run, $from, $to, $actor, $reason);
            $this->afterChange($run, $actor, true);

            return $section->refresh();
        });
    }

    private function apply(PayrollRunSection $section, PayrollRun $run, string $from, string $to, Admin $actor, ?string $reason): void
    {
        if ($to === PayrollRunSection::STATUS_REVIEWED && ! $section->items()->exists()) {
            $this->fail('A payroll run with no lines cannot be reviewed.', 'PAYROLL_RUN_EMPTY');
        }

        $stamp = match ($to) {
            PayrollRunSection::STATUS_REVIEWED => ['reviewed_at' => now(), 'reviewed_by' => $actor->id],
            PayrollRunSection::STATUS_APPROVED => ['approved_at' => now(), 'approved_by' => $actor->id],
            PayrollRunSection::STATUS_FINALIZED => ['finalized_at' => now(), 'finalized_by' => $actor->id],
            default => ['reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null],
        };

        $section->update(['status' => $to, ...$stamp]);

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
