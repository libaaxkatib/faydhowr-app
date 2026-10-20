<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\EmployeePayment;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a money transaction of the corrections ledger that was recorded in error. Nothing is deleted and no original row is edited:
 *
 *  - a DEBT REPAYMENT (Super Admin or HR Manager) is marked void with who/when/why and the debt is restored;
 *  - an UNDERPAYMENT PAYMENT (Super Admin only) is cancelled by a LINKED REVERSAL: a new ledger transaction that points at the payment, and a new
 *    employee-payment-ledger row with the same amount, negative, that points at the original payment row. The original payment and its ledger row
 *    stay exactly as they were, every total that sums the payment ledger nets to zero for the pair, and the amount owed to the employee is restored.
 *    A payment can be reversed once (unique link in the database and a row lock here);
 *  - a write-off and a reversal themselves are never voided.
 */
class VoidCorrectionTransactionAction
{
    public function handle(PayrollCorrectionTransaction $transaction, string $reason, Admin $actor): PayrollCorrection
    {
        RecordCorrectionTransactionAction::authorize($actor, $transaction->type === PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT
            ? PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT
            : PayrollCorrectionTransaction::TYPE_DEBT_REPAYMENT);

        return DB::transaction(function () use ($transaction, $reason, $actor): PayrollCorrection {
            $correction = PayrollCorrection::query()->lockForUpdate()->findOrFail($transaction->payroll_correction_id);
            $transaction = PayrollCorrectionTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            return match ($transaction->type) {
                PayrollCorrectionTransaction::TYPE_DEBT_REPAYMENT => $this->voidRepayment($correction, $transaction, $reason, $actor),
                PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT => $this->reversePayment($correction, $transaction, $reason, $actor),
                default => $this->fail('A write-off or a reversal cannot be voided: they stay in the ledger as recorded.', 'TRANSACTION_NOT_VOIDABLE'),
            };
        });
    }

    private function voidRepayment(PayrollCorrection $correction, PayrollCorrectionTransaction $transaction, string $reason, Admin $actor): PayrollCorrection
    {
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
                'debt_reference' => $correction->id,
                'employee_id' => $correction->employee_id,
                'amount_cents' => (int) $transaction->amount_cents,
                'outstanding_before_cents' => $before,
                'outstanding_after_cents' => (int) $correction->outstanding_cents,
                'reason' => $reason,
            ],
        ));

        return $correction->refresh();
    }

    private function reversePayment(PayrollCorrection $correction, PayrollCorrectionTransaction $transaction, string $reason, Admin $actor): PayrollCorrection
    {
        if ($transaction->isVoided()) {
            $this->fail('This payment is already voided.', 'TRANSACTION_ALREADY_VOIDED');
        }

        $original = EmployeePayment::query()->lockForUpdate()->find($transaction->employee_payment_id);

        if ($original === null) {
            $this->fail('The payment ledger entry of this transaction is missing, so it cannot be reversed safely.', 'PAYMENT_LEDGER_ENTRY_MISSING');
        }

        $cents = (int) $transaction->amount_cents;
        $before = (int) $correction->outstanding_cents;
        $today = now()->toDateString();

        $reversalPayment = EmployeePayment::query()->create([
            'employee_id' => $original->employee_id,
            'payroll_item_id' => null,
            'reverses_payment_id' => $original->id,
            'payment_date' => $today,
            'amount' => PayrollMoney::format(-$cents),
            'currency' => $original->currency,
            'notes' => "Reversal of payment #{$original->id} (underpayment correction #{$correction->id}): {$reason}",
            'paid_by' => $actor->id,
        ]);

        $reversal = PayrollCorrectionTransaction::query()->create([
            'payroll_correction_id' => $correction->id,
            'type' => PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_REVERSAL,
            'amount_cents' => $cents,
            'transaction_date' => $today,
            'reason' => $reason,
            'balance_before_cents' => $before,
            'balance_after_cents' => $before + $cents,
            'reverses_transaction_id' => $transaction->id,
            'idempotency_key' => 'void-'.$transaction->id,
            'employee_payment_id' => $reversalPayment->id,
            'recorded_by' => $actor->id,
        ]);

        $correction->refreshBalance();

        event(AuditEvent::record(
            action: AuditAction::Payment,
            admin: $actor,
            description: 'Underpayment payment #'.$transaction->id.' voided ('.PayrollMoney::format($cents).' USD) for payroll correction #'.$correction->id.': '.$reason,
            entityType: PayrollCorrection::class,
            entityId: $correction->id,
            metadata: [
                'event' => 'underpayment_payment_voided',
                'original_transaction_id' => $transaction->id,
                'reversal_transaction_id' => $reversal->id,
                'original_employee_payment_id' => $original->id,
                'reversal_employee_payment_id' => $reversalPayment->id,
                'debt_reference' => $correction->id,
                'employee_id' => $correction->employee_id,
                'amount_cents' => $cents,
                'owed_before_cents' => $before,
                'owed_after_cents' => (int) $correction->outstanding_cents,
                'employee_payment_ledger_net_cents' => (int) round(((float) EmployeePayment::query()->where('employee_id', $correction->employee_id)->where(fn ($q) => $q->where('id', $original->id)->orWhere('id', $reversalPayment->id))->sum('amount')) * 100),
                'reason' => $reason,
            ],
        ));

        return $correction->refresh();
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
