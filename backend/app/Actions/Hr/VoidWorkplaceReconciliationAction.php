<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollWorkplaceReconciliation;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\CorrectionAuthority;
use App\Support\Hr\Payroll\PayrollApproval;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/** Voids (never deletes) a reconciliation entry recorded by mistake; the excess it settled becomes unsettled again and the company's approval is withdrawn. */
class VoidWorkplaceReconciliationAction
{
    public function handle(PayrollWorkplaceReconciliation $entry, string $reason, Admin $actor): PayrollWorkplaceReconciliation
    {
        if (! CorrectionAuthority::mayReconcile($actor)) {
            $this->fail('Only a Super Admin or an HR Manager can void a reconciliation. Nothing was changed.', 'RECONCILIATION_NOT_ALLOWED', 403);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            $this->fail('A reason is required to void a reconciliation (at least 5 characters). Nothing was changed.', 'VOID_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($entry, $reason, $actor): PayrollWorkplaceReconciliation {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($entry->payroll_run_id);
            $entry = PayrollWorkplaceReconciliation::query()->lockForUpdate()->findOrFail($entry->id);

            if ($entry->voided_at !== null) {
                $this->fail('This reconciliation was already voided.', 'RECONCILIATION_ALREADY_VOIDED');
            }

            PayrollApproval::track($run, fn () => $entry->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]), $actor, ['source' => 'workplace_reconciliation_voided', 'reconciliation_id' => $entry->id, 'cause' => 'a reconciliation was voided']);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Payroll {$run->period} reconciliation #{$entry->id} voided (".PayrollMoney::format((int) $entry->amount_cents).' USD): '.$reason,
                entityType: PayrollWorkplaceReconciliation::class,
                entityId: $entry->id,
                metadata: ['event' => 'workplace_reconciliation_voided', 'payroll_run_id' => $run->id, 'period' => $run->period, 'client_company_id' => $entry->client_company_id, 'amount_cents' => (int) $entry->amount_cents, 'reason' => $reason],
            ));

            return $entry->refresh();
        });
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
