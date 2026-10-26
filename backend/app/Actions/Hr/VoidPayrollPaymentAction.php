<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeePayment;
use App\Models\PayrollItem;
use App\Models\PayrollPaymentVoid;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityCalendar;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Voids a payroll payment that was marked Paid (owner rule: Super Admin and HR Manager, with a mandatory reason).
 *
 * The original employee payment and its payment-ledger row are NEVER edited or deleted. In one transaction this adds:
 *  - a reversal row in the payment ledger: the same amount, negative, linked to the original (`reverses_payment_id`, unique = once only);
 *  - a linked correction entry (`payroll_payment_voids`) with the line, both payments, the amount, the reason and the actor;
 *  - an audit event;
 * and puts the payroll LINE back to "unpaid" (the state of the line, not the payment), so it can be paid again correctly. Every total that sums
 * the payment ledger (HR dashboard, financial ledger, HR reports) nets to zero for the pair. The role is checked here on the server, a second
 * void of the same payment is refused (the line is no longer paid; the database also refuses a second reversal), and the payroll run itself is
 * never touched.
 */
class VoidPayrollPaymentAction
{
    public function handle(PayrollItem $item, string $reason, Admin $actor): PayrollItem
    {
        if (! in_array($actor->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true)) {
            $this->fail('Only a Super Admin or an HR Manager can void a payroll payment.', 'PAYROLL_PAYMENT_VOID_NOT_ALLOWED', 403);
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            $this->fail('A reason is required to void a payment (at least 5 characters). Nothing was changed.', 'VOID_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($item, $reason, $actor): PayrollItem {
            $item = PayrollItem::query()->with('run')->lockForUpdate()->findOrFail($item->id);

            if ($item->run->status !== PayrollRun::STATUS_FINALIZED || $item->payment_status !== 'paid' || $item->employee_payment_id === null) {
                $this->fail('Only a payroll line that is marked paid can have its payment voided. This line is not paid (it may already have been voided).', 'PAYROLL_ITEM_NOT_PAID');
            }

            $original = EmployeePayment::query()->lockForUpdate()->find($item->employee_payment_id);

            if ($original === null) {
                $this->fail('The payment ledger entry of this line is missing, so it cannot be reversed safely.', 'PAYMENT_LEDGER_ENTRY_MISSING');
            }

            if ($original->reverses_payment_id !== null || EmployeePayment::query()->where('reverses_payment_id', $original->id)->exists()) {
                $this->fail('This payment was already voided.', 'PAYMENT_ALREADY_VOIDED');
            }

            $cents = (int) round(((float) $original->amount) * 100);
            $today = FacilityCalendar::today()->toDateString();

            $reversal = EmployeePayment::query()->create([
                'employee_id' => $original->employee_id,
                'payroll_item_id' => null,
                'reverses_payment_id' => $original->id,
                'payment_date' => $today,
                'amount' => PayrollMoney::format(-$cents),
                'currency' => $original->currency,
                'notes' => "Reversal of payroll payment #{$original->id} ({$item->period}, line #{$item->id}): {$reason}",
                'paid_by' => $actor->id,
            ]);

            $void = PayrollPaymentVoid::query()->create([
                'payroll_item_id' => $item->id,
                'employee_id' => $original->employee_id,
                'original_payment_id' => $original->id,
                'reversal_payment_id' => $reversal->id,
                'amount_cents' => $cents,
                'reason' => $reason,
                'voided_by' => $actor->id,
            ]);

            // The line goes back to unpaid so it can be paid again; the original payment row is not touched.
            $item->update(['payment_status' => 'unpaid', 'paid_at' => null, 'employee_payment_id' => null]);

            event(AuditEvent::record(
                action: AuditAction::Payment,
                admin: $actor,
                description: "Payroll {$item->period} payment #{$original->id} voided (".PayrollMoney::format($cents).' USD) for line #'.$item->id.': '.$reason,
                entityType: PayrollItem::class,
                entityId: $item->id,
                metadata: [
                    'event' => 'payroll_payment_voided',
                    'void_id' => $void->id,
                    'payroll_item_id' => $item->id,
                    'payroll_run_id' => $item->payroll_run_id,
                    'period' => $item->period,
                    'employee_id' => $original->employee_id,
                    'original_payment_id' => $original->id,
                    'reversal_payment_id' => $reversal->id,
                    'amount_cents' => $cents,
                    'payment_ledger_net_cents_for_the_pair' => 0,
                    'reason' => $reason,
                ],
            ));

            return $item->refresh();
        });
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
