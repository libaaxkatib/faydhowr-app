<?php

namespace App\Actions\Hr;

use App\Enums\AdminRole;
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
 * Money that moves OUTSIDE a payroll run for a correction, always as its own traceable transaction (the closed payroll month is never touched):
 *
 *  - underpayment_payment: the employee is paid what a closed month owed them. Super Admin only. Also written to the employee payment ledger
 *    (the same ledger "mark as paid" uses). It records a payment HR has made; it moves no money. Voiding it is a separate, linked reversal.
 *  - debt_repayment: the employee repaid part of an overpayment that payroll could not recover (typically after leaving). Super Admin and HR Manager.
 *  - debt_write_off: part or all of the remaining debt is forgiven. Super Admin only, with a mandatory reason. The debt record, its deductions
 *    and repayments stay; the write-off is one more line of the ledger with the balance before and after, and it cannot be undone silently.
 *
 * Authorization is checked here, on the server. The correction is locked while this runs, an amount can never exceed what is still open (draft
 * payroll months that already schedule a deduction are counted as taken), and the client's idempotency key turns a retry or a double click into a
 * no-op that returns the first transaction.
 */
class RecordCorrectionTransactionAction
{
    /**
     * @return array{transaction: PayrollCorrectionTransaction, correction: PayrollCorrection, replayed: bool}
     */
    public function handle(PayrollCorrection $correction, string $type, int $amountCents, string $date, ?string $note, string $idempotencyKey, Admin $actor, ?string $reason = null): array
    {
        self::authorize($actor, $type);

        $reason = $reason === null ? null : trim($reason);

        if ($type === PayrollCorrectionTransaction::TYPE_DEBT_WRITE_OFF && ($reason === null || mb_strlen($reason) < 5)) {
            $this->fail('A write-off needs a reason (at least 5 characters). Nothing was recorded.', 'WRITE_OFF_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($correction, $type, $amountCents, $date, $note, $idempotencyKey, $actor, $reason): array {
            $correction = PayrollCorrection::query()->lockForUpdate()->findOrFail($correction->id);

            $existing = PayrollCorrectionTransaction::query()->where('payroll_correction_id', $correction->id)->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->type !== $type || (int) $existing->amount_cents !== $amountCents) {
                    $this->fail('This request key was already used for a different transaction. Nothing was recorded.', 'IDEMPOTENCY_KEY_REUSED', 409);
                }

                return ['transaction' => $existing, 'correction' => $correction, 'replayed' => true];
            }

            $expected = $type === PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT ? PayrollCorrection::KIND_UNDERPAYMENT : PayrollCorrection::KIND_OVERPAYMENT;

            if ($correction->kind !== $expected) {
                $this->fail($expected === PayrollCorrection::KIND_UNDERPAYMENT ? 'A payment can only be recorded for an underpayment.' : 'A repayment or write-off can only be recorded for an overpayment (a debt of the employee).', 'CORRECTION_WRONG_KIND');
            }

            if ($amountCents <= 0) {
                $this->fail('The amount must be greater than zero.', 'AMOUNT_INVALID');
            }

            $available = $correction->availableToSettleCents();

            if ($amountCents > $available) {
                $this->fail('The amount is more than what is still open ('.PayrollMoney::format($available).' USD'.($correction->kind === PayrollCorrection::KIND_OVERPAYMENT ? ', after the deductions already scheduled in draft payroll months' : '').'). Nothing was recorded.', 'AMOUNT_EXCEEDS_OUTSTANDING');
            }

            $before = (int) $correction->outstanding_cents;
            $payment = null;

            if ($type === PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT) {
                $payment = EmployeePayment::query()->create([
                    'employee_id' => $correction->employee_id,
                    'payroll_item_id' => null,
                    'payment_date' => $date,
                    'amount' => PayrollMoney::format($amountCents),
                    'currency' => PayrollMoney::CURRENCY,
                    'notes' => "Underpayment correction #{$correction->id} ({$correction->period})".($note ? ": {$note}" : ''),
                    'paid_by' => $actor->id,
                ]);
            }

            $transaction = PayrollCorrectionTransaction::query()->create([
                'payroll_correction_id' => $correction->id,
                'type' => $type,
                'amount_cents' => $amountCents,
                'transaction_date' => $date,
                'note' => $note,
                'reason' => $reason,
                'balance_before_cents' => $before,
                'balance_after_cents' => $before - $amountCents,
                'idempotency_key' => $idempotencyKey,
                'employee_payment_id' => $payment?->id,
                'recorded_by' => $actor->id,
            ]);

            $correction->refreshBalance();

            [$event, $description] = match ($type) {
                PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT => ['underpayment_paid', 'Underpayment paid'],
                PayrollCorrectionTransaction::TYPE_DEBT_WRITE_OFF => ['debt_written_off', 'Debt written off'],
                default => ['debt_repayment_recorded', 'Debt repayment recorded'],
            };

            event(AuditEvent::record(
                action: $payment !== null ? AuditAction::Payment : AuditAction::Update,
                admin: $actor,
                description: $description.' for payroll correction #'.$correction->id.' ('.PayrollMoney::format($amountCents).' USD).'.($reason !== null ? " Reason: {$reason}" : ''),
                entityType: PayrollCorrection::class,
                entityId: $correction->id,
                metadata: [
                    'event' => $event,
                    'transaction_id' => $transaction->id,
                    'debt_reference' => $correction->id,
                    'employee_id' => $correction->employee_id,
                    'period' => $correction->period,
                    'amount_cents' => $amountCents,
                    'outstanding_before_cents' => $before,
                    'outstanding_after_cents' => (int) $correction->outstanding_cents,
                    'employee_payment_id' => $payment?->id,
                    'transaction_date' => $date,
                    'note' => $note,
                    'reason' => $reason,
                ],
            ));

            return ['transaction' => $transaction, 'correction' => $correction->refresh(), 'replayed' => false];
        });
    }

    /** Super Admin pays underpayments and writes debts off; Super Admin and HR Manager manage repayments. Anyone else is refused, whatever the screen offered. */
    public static function authorize(Admin $actor, string $type): void
    {
        $superOnly = in_array($type, [PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT, PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_REVERSAL, PayrollCorrectionTransaction::TYPE_DEBT_WRITE_OFF], true);
        $allowed = $superOnly ? [AdminRole::SuperAdmin] : [AdminRole::SuperAdmin, AdminRole::HrManager];

        if (! in_array($actor->role, $allowed, true)) {
            throw new HttpResponseException(ApiResponse::error(
                match ($type) {
                    PayrollCorrectionTransaction::TYPE_DEBT_WRITE_OFF => 'Only a Super Admin can write off a debt.',
                    PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT, PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_REVERSAL => 'Only a Super Admin can pay or void an underpayment.',
                    default => 'Only a Super Admin or an HR Manager can manage an employee debt.',
                },
                'PAYROLL_CORRECTION_NOT_ALLOWED',
                403,
            ));
        }
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
