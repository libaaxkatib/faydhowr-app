<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeePayment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Records that a finalized payroll line was paid by writing the net amount to
 * the existing employee payment ledger, linked to the line. A line can be paid
 * once (unique link) and only after the run is finalized. This records a
 * payment that HR has actually made; it does not move money.
 */
class MarkPayrollItemPaidAction
{
    public function handle(PayrollItem $item, ?string $paymentDate, Admin $actor): PayrollItem
    {
        return DB::transaction(function () use ($item, $paymentDate, $actor) {
            $item = PayrollItem::query()->with('run')->lockForUpdate()->findOrFail($item->id);

            if ($item->run->status !== PayrollRun::STATUS_FINALIZED) {
                $this->fail('Only lines of a finalized payroll can be marked paid.', 'PAYROLL_RUN_NOT_FINALIZED');
            }

            if ($item->voided_at !== null) {
                $this->fail('A voided payroll line cannot be paid.', 'PAYROLL_ITEM_VOIDED');
            }

            if ($item->payment_status === 'paid') {
                $this->fail('This payroll line is already marked paid.', 'PAYROLL_ITEM_ALREADY_PAID');
            }

            if ($item->net_cents <= 0) {
                $this->fail('There is no positive net amount to pay on this line.', 'PAYROLL_ITEM_NOTHING_TO_PAY');
            }

            $payment = EmployeePayment::query()->create([
                'employee_id' => $item->employee_id,
                'payroll_item_id' => $item->id,
                'payment_date' => $paymentDate ?? now()->toDateString(),
                'amount' => PayrollMoney::format($item->net_cents),
                'currency' => PayrollMoney::CURRENCY,
                'notes' => "Payroll {$item->period}",
                'paid_by' => $actor->id,
            ]);

            $item->update(['payment_status' => 'paid', 'paid_at' => now(), 'employee_payment_id' => $payment->id]);

            event(AuditEvent::record(
                action: AuditAction::Payment,
                admin: $actor,
                description: "Payroll {$item->period} line #{$item->id} marked paid (".PayrollMoney::format($item->net_cents).' USD).',
                entityType: PayrollItem::class,
                entityId: $item->id,
                metadata: ['employee_payment_id' => $payment->id],
            ));

            return $item;
        });
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
