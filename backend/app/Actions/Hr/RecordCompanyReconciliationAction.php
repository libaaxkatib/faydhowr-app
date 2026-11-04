<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\PayrollWorkplaceReconciliation;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityCalendar;
use App\Support\Hr\Payroll\CorrectionAuthority;
use App\Support\Hr\Payroll\PayrollApproval;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\WorkplacePayroll;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Settles the EXCESS of an overpaid company (paid more than its corrected, finalized payroll says) - the "adjustment / reconciliation" the owner asked for
 * when a correction touches money already paid (decision 2026-11-04). It never changes, moves or deletes a payment and never edits payroll; it records
 * how the excess was settled (returned, carried forward to a later month, or otherwise) with a reason, who and when. Once every cent of the excess is
 * settled the company is approved again (PayrollApproval is derived), and the audit log records the moment it flips.
 *
 * Like payments: the run row is locked, an entry can never settle more than the unsettled excess, an idempotency key turns a retry into a no-op, and a
 * mistaken entry is voided (never deleted) with a reason. A Super Admin or an HR Manager may do it (CorrectionAuthority).
 */
class RecordCompanyReconciliationAction
{
    public const MODE_FULL = 'full';

    public const MODE_PARTIAL = 'partial';

    /**
     * @return array{entries: list<PayrollWorkplaceReconciliation>, replayed: bool}
     */
    public function handle(PayrollRunSection $section, string $mode, ?int $amountCents, string $method, string $reason, string $settledOn, string $idempotencyKey, Admin $actor): array
    {
        if (! CorrectionAuthority::mayReconcile($actor)) {
            $this->fail('Only a Super Admin or an HR Manager can reconcile an overpaid workplace. Nothing was recorded.', 'RECONCILIATION_NOT_ALLOWED', 403);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            $this->fail('A reason is required (at least 5 characters). Nothing was recorded.', 'RECONCILIATION_REASON_REQUIRED');
        }

        if (! in_array($method, PayrollWorkplaceReconciliation::METHODS, true)) {
            $this->fail('Choose how the excess was settled: returned, carried forward or other.', 'RECONCILIATION_METHOD_INVALID');
        }

        return DB::transaction(function () use ($section, $mode, $amountCents, $method, $reason, $settledOn, $idempotencyKey, $actor): array {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($section->payroll_run_id);
            $section = PayrollRunSection::query()->findOrFail($section->id);
            $companyId = $section->client_company_id === null ? null : (int) $section->client_company_id;

            $existing = PayrollWorkplaceReconciliation::query()->where('payroll_run_id', $run->id)->where('idempotency_key', 'like', $this->prefix($idempotencyKey).'-%')->orderBy('id')->get();

            if ($existing->isNotEmpty()) {
                return ['entries' => $existing->all(), 'replayed' => true];
            }

            if ($section->status !== PayrollRunSection::STATUS_FINALIZED) {
                $this->fail('Only a finalized company payroll can be reconciled. Finalize the corrected payroll first.', 'PAYROLL_RUN_NOT_FINALIZED');
            }

            $workplaces = array_values(array_filter(
                WorkplacePayroll::summaries($run),
                fn (array $w): bool => $w['payable'] && $w['client_company_id'] === $companyId && $w['unreconciled_overpaid_cents'] > 0,
            ));
            $unsettled = (int) array_sum(array_column($workplaces, 'unreconciled_overpaid_cents'));

            if ($unsettled <= 0) {
                $this->fail('This company has no overpayment left to reconcile. Nothing was recorded.', 'NOTHING_TO_RECONCILE');
            }

            $left = $mode === self::MODE_FULL ? $unsettled : (int) $amountCents;

            if ($mode !== self::MODE_FULL && $left <= 0) {
                $this->fail('The amount must be greater than zero.', 'AMOUNT_INVALID');
            }

            if ($left > $unsettled) {
                $this->fail('The amount is more than the overpayment still to be settled ('.PayrollMoney::format($unsettled).' USD). Nothing was recorded.', 'AMOUNT_EXCEEDS_OVERPAID');
            }

            if ($settledOn > FacilityCalendar::today()->toDateString()) {
                $this->fail('The date cannot be in the future.', 'PAYMENT_DATE_IN_FUTURE');
            }

            $entries = [];

            PayrollApproval::track($run, function () use ($workplaces, &$left, $run, $method, $reason, $settledOn, $idempotencyKey, $actor, &$entries): void {
                foreach ($workplaces as $index => $workplace) {
                    $take = min($left, (int) $workplace['unreconciled_overpaid_cents']);

                    if ($take <= 0) {
                        continue;
                    }

                    $entries[] = PayrollWorkplaceReconciliation::query()->create([
                        'payroll_run_id' => $run->id,
                        'period' => $run->period,
                        'client_company_id' => $workplace['client_company_id'],
                        'work_location_id' => $workplace['work_location_id'],
                        'amount_cents' => $take,
                        'method' => $method,
                        'reason' => $reason,
                        'settled_on' => $settledOn,
                        'idempotency_key' => $this->prefix($idempotencyKey).'-'.$index,
                        'recorded_by' => $actor->id,
                        'recorded_by_role' => $actor->role->value,
                    ]);
                    $left -= $take;
                }
            }, $actor, ['source' => 'workplace_reconciliation', 'method' => $method]);

            $name = $section->clientCompany?->name ?? 'No company';

            foreach ($entries as $entry) {
                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Payroll {$run->period} overpayment of {$name} reconciled: ".PayrollMoney::format((int) $entry->amount_cents)." USD ({$method}): {$reason}",
                    entityType: PayrollWorkplaceReconciliation::class,
                    entityId: $entry->id,
                    metadata: [
                        'event' => 'workplace_reconciliation_recorded',
                        'payroll_run_id' => $run->id,
                        'period' => $run->period,
                        'client_company_id' => $entry->client_company_id,
                        'work_location_id' => $entry->work_location_id,
                        'amount_cents' => (int) $entry->amount_cents,
                        'method' => $method,
                        'reason' => $reason,
                        'recorded_by_role' => $actor->role->value,
                        'unsettled_before_cents' => $unsettled,
                    ],
                ));
            }

            return ['entries' => $entries, 'replayed' => false];
        });
    }

    /** The column holds 64 characters: a fixed-size digest of the client's key plus the workplace index fits. */
    private function prefix(string $idempotencyKey): string
    {
        return 'rc-'.substr(sha1($idempotencyKey), 0, 40);
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
