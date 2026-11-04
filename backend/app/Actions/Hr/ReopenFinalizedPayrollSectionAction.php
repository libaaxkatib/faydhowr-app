<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\PayrollSectionReopening;
use App\Models\PayrollWorkplacePayment;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\CorrectionAuthority;
use App\Support\Hr\Payroll\CorrectionSupersession;
use App\Support\Hr\Payroll\PayrollApproval;
use App\Support\Hr\Payroll\PayrollSections;
use App\Support\Hr\Payroll\RecoveryLedger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Reopens ONE company's FINALIZED payroll so a mistake in it can be corrected (owner decision 2026-11-04: a Super Admin or an HR Manager may, with a reason).
 * Until now a finalized company could never be reopened and a mistake in it could only be patched with later-month adjustments.
 *
 * What it does: the company's section goes back to DRAFT (its lines are kept as they were until the draft is generated again), the month's own status follows,
 * and one PayrollSectionReopening records who, in which role, when, why and what the payroll held (totals, money already paid).
 * What it never does: touch a payment. Every workplace payment stays exactly as recorded; the company simply stops being "approved" (approval is derived:
 * finalized AND paid in full). When the corrected payroll is finalized again, outstanding = corrected total - what was already paid; if more was paid than
 * the corrected total, the excess shows as OVERPAID until a reconciliation settles it (RecordWorkplaceReconciliationAction).
 *
 * Refused: a reason shorter than MIN_REASON characters; a company whose lines were marked paid one by one (their payments must be voided first, because
 * regenerating the draft rebuilds the lines); anything that is not finalized (reviewed payroll is reopened by the ordinary reopen).
 */
class ReopenFinalizedPayrollSectionAction
{
    public const MIN_REASON = 10;

    public function handle(PayrollRunSection $section, string $reason, Admin $actor): PayrollRunSection
    {
        if (! CorrectionAuthority::mayReopenFinalized($actor)) {
            $this->fail('Only a Super Admin or an HR Manager can reopen finalized payroll. Nothing was changed.', 'FINALIZED_REOPEN_NOT_ALLOWED', 403);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            $this->fail('Enter the reason for reopening finalized payroll (at least '.self::MIN_REASON.' characters). Nothing was changed.', 'REOPEN_REASON_REQUIRED', 422, ['reason' => ['A reason is required.']]);
        }

        return DB::transaction(function () use ($section, $reason, $actor): PayrollRunSection {
            // The run first, then the section: the same order Generate, the transitions and the payment actions lock in.
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($section->payroll_run_id);
            $section = PayrollRunSection::query()->lockForUpdate()->findOrFail($section->id);
            $name = $section->clientCompany?->name ?? 'No company';

            if ($section->status !== PayrollRunSection::STATUS_FINALIZED) {
                $this->fail("Payroll {$run->period} for {$name} is {$section->status}, not finalized, so there is nothing to reopen this way.", 'PAYROLL_RUN_WRONG_STATE', 422);
            }

            if ($section->items()->whereNull('voided_at')->where('payment_status', 'paid')->exists()) {
                $this->fail("Some payroll lines of {$name} were marked paid one by one. Void those payments first (payroll is rebuilt when it is corrected), then reopen. Nothing was changed.", 'PAYROLL_LINES_PAID', 422);
            }

            // P1: open corrections of this company and month become obsolete when it is rebuilt; refused when money already moved for one of them.
            CorrectionSupersession::assertCanSupersede($section, $run->period);

            $payments = PayrollWorkplacePayment::query()
                ->where('payroll_run_id', $run->id)
                ->when($section->client_company_id === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $section->client_company_id))
                ->whereNull('voided_at');

            $reopening = PayrollSectionReopening::query()->create([
                'payroll_run_id' => $run->id,
                'payroll_run_section_id' => $section->id,
                'period' => $run->period,
                'client_company_id' => $section->client_company_id,
                'reason' => $reason,
                'reopened_by' => $actor->id,
                'reopened_by_role' => $actor->role->value,
                'reopened_at' => now(),
                'previous_finalized_at' => $section->finalized_at,
                'previous_finalized_by' => $section->finalized_by,
                'totals_before' => $section->totals,
                'paid_cents_at_reopen' => (int) (clone $payments)->sum('amount_cents'),
                'payment_count_at_reopen' => (clone $payments)->count(),
            ]);

            PayrollApproval::track($run, function () use ($section, $run, $actor, $reason): void {
                $section->update(['status' => PayrollRunSection::STATUS_DRAFT, 'reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null, 'finalized_at' => null, 'finalized_by' => null]);
                CorrectionSupersession::supersede($section, $run->period, $actor, $reason);
                $run = PayrollSections::syncRun($run->refresh());
                RecoveryLedger::syncFromRun($run);
            }, $actor, ['cause' => 'its finalized payroll was reopened for correction', 'reopening_id' => $reopening->id]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Finalized payroll {$run->period} for {$name} reopened for correction: {$reason}",
                entityType: PayrollRunSection::class,
                entityId: $section->id,
                metadata: [
                    'event' => 'payroll_section_reopened_from_finalized',
                    'reopening_id' => $reopening->id,
                    'payroll_run_id' => $run->id,
                    'period' => $run->period,
                    'client_company_id' => $section->client_company_id,
                    'company' => $name,
                    'reason' => $reason,
                    'reopened_by_admin_id' => $actor->id,
                    'reopened_by_role' => $actor->role->value,
                    'previous_status' => PayrollRunSection::STATUS_FINALIZED,
                    'new_status' => PayrollRunSection::STATUS_DRAFT,
                    'totals_before' => $reopening->totals_before,
                    'paid_cents_at_reopen' => $reopening->paid_cents_at_reopen,
                    'payment_count_at_reopen' => $reopening->payment_count_at_reopen,
                    'payments_preserved' => true,
                ],
            ));

            return $section->refresh();
        });
    }

    private function fail(string $message, string $code, int $status, ?array $errors = null): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status, $errors));
    }
}
