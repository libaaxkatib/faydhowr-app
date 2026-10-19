<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a debt repayment that was recorded by mistake. The transaction is kept (who, when, why it was voided) and the debt is
 * restored. Super Admin and HR Manager only. A payment of an underpayment cannot be voided here: it was written to the payment ledger as
 * money paid out, and a payment ledger entry is never silently erased.
 */
class VoidCorrectionTransactionAction
{
    public function handle(PayrollCorrectionTransaction $transaction, string $reason, Admin $actor): PayrollCorrection
    {
        RecordCorrectionTransactionAction::authorize($actor, PayrollCorrectionTransaction::TYPE_DEBT_REPAYMENT);

        return DB::transaction(function () use ($transaction, $reason, $actor): PayrollCorrection {
            $correction = PayrollCorrection::query()->lockForUpdate()->findOrFail($transaction->payroll_correction_id);
            $transaction = PayrollCorrectionTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->type !== PayrollCorrectionTransaction::TYPE_DEBT_REPAYMENT) {
                $this->fail('Only a debt repayment can be voided. A payment to the employee stays in the payment ledger.', 'TRANSACTION_NOT_VOIDABLE');
            }

            if ($transaction->isVoided()) {
                $this->fail('This repayment is already voided.', 'TRANSACTION_ALREADY_VOIDED');
            }

            $transaction->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            $before = (int) $correction->outstanding_cents;
            $correction->refreshBalance();

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: 'Debt repayment #'.$transaction->id.' voided ('.PayrollMoney::format((int) $transaction->amount_cents).' USD) for payroll correction #'.$correction->id.': '.$reason,
                entityType: PayrollCorrection::class,
                entityId: $correction->id,
                metadata: [
                    'event' => 'debt_repayment_voided',
                    'transaction_id' => $transaction->id,
                    'employee_id' => $correction->employee_id,
                    'amount_cents' => (int) $transaction->amount_cents,
                    'outstanding_before_cents' => $before,
                    'outstanding_after_cents' => (int) $correction->outstanding_cents,
                    'reason' => $reason,
                ],
            ));

            return $correction->refresh();
        });
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
