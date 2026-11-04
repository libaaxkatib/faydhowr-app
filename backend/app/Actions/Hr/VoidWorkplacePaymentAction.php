<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollWorkplacePayment;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollApproval;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\WorkplacePayroll;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Voids a workplace payment recorded by mistake (Super Admin and HR Manager, with a mandatory reason, the same rule as voiding a single-line payment).
 * The row is never edited or deleted: it is marked voided with who, when and why, stops counting toward the workplace's paid total, and stays in its
 * history. A second void of the same payment is refused.
 */
class VoidWorkplacePaymentAction
{
    /** @return array<string, mixed> the workplace's summary after the void */
    public function handle(PayrollWorkplacePayment $payment, string $reason, Admin $actor): array
    {
        if (! in_array($actor->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true)) {
            $this->fail('Only a Super Admin or an HR Manager can void a payroll payment.', 'PAYROLL_PAYMENT_VOID_NOT_ALLOWED', 403);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            $this->fail('A reason is required to void a payment (at least 5 characters). Nothing was changed.', 'VOID_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($payment, $reason, $actor): array {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($payment->payroll_run_id);
            $payment = PayrollWorkplacePayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->voided_at !== null) {
                $this->fail('This payment was already voided.', 'PAYMENT_ALREADY_VOIDED');
            }

            // Voiding can take a company (and the month) back out of Approved: PayrollApproval records it.
            PayrollApproval::track($run, fn () => $payment->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]), $actor, ['source' => 'workplace_payment_voided', 'payment_id' => $payment->id]);

            $summary = WorkplacePayroll::summaryFor($run, $payment->client_company_id, $payment->work_location_id) ?? [];

            event(AuditEvent::record(
                action: AuditAction::Payment,
                admin: $actor,
                description: "Payroll {$run->period} workplace payment #{$payment->id} voided (".PayrollMoney::format((int) $payment->amount_cents).' USD): '.$reason,
                entityType: PayrollWorkplacePayment::class,
                entityId: $payment->id,
                metadata: [
                    'event' => 'workplace_payment_voided',
                    'payroll_run_id' => $run->id,
                    'period' => $run->period,
                    'client_company_id' => $payment->client_company_id,
                    'work_location_id' => $payment->work_location_id,
                    'amount_cents' => (int) $payment->amount_cents,
                    'outstanding_after_cents' => (int) ($summary['outstanding_cents'] ?? 0),
                    'status_after' => $summary['status'] ?? null,
                    'reason' => $reason,
                ],
            ));

            return $summary;
        });
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
