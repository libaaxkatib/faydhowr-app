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
 * Money that moves OUTSIDE a payroll run for a correction, always as its own traceable transaction:
 *
 *  - underpayment_payment: the employee is paid what a closed month owed them. Super Admin only. Also written to the employee payment
 *    ledger (the same ledger "mark as paid" uses), so it shows in the financial ledger. It records a payment HR has made; it moves no money.
 *  - debt_repayment: the employee repaid part of an overpayment that payroll could not recover (typically after leaving). Super Admin and
 *    HR Manager.
 *
 * Authorization is checked here, on the server. The correction is locked while this runs, an amount can never exceed what is still owed
 * (draft payroll months that already schedule a deduction are counted as owed-to-payroll), and the client's idempotency key turns a
 * retry or a double click into a no-op that returns the first transaction. The closed payroll month is never touched.
 */
class RecordCorrectionTransactionAction
{
    /**
     * @return array{transaction: PayrollCorrectionTransaction, correction: PayrollCorrection, replayed: bool}
     */
    public function handle(PayrollCorrection $correction, string $type, int $amountCents, string $date, ?string $note, string $idempotencyKey, Admin $actor): array
    {
        self::authorize($actor, $type);

        return DB::transaction(function () use ($correction, $type, $amountCents, $date, $note, $idempotencyKey, $actor): array {
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
                $this->fail($expected === PayrollCorrection::KIND_UNDERPAYMENT ? 'A payment can only be recorded for an underpayment.' : 'A repayment can only be recorded for an overpayment (a debt of the employee).', 'CORRECTION_WRONG_KIND');
            }

            if ($amountCents <= 0) {
                $this->fail('The amount must be greater than zero.', 'AMOUNT_INVALID');
            }

            $available = $correction->availableToSettleCents();

            if ($amountCents > $available) {
                $this->fail('The amount is more than what is still open ('.PayrollMoney::format($available).' USD'.($correction->kind === PayrollCorrection::KIND_OVERPAYMENT ? ', after the deductions already scheduled in draft payroll months' : '').'). Nothing was recorded.', 'AMOUNT_EXCEEDS_OUTSTANDING');
            }

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
                'idempotency_key' => $idempotencyKey,
                'employee_payment_id' => $payment?->id,
                'recorded_by' => $actor->id,
            ]);

            $before = (int) $correction->outstanding_cents;
            $correction->refreshBalance();

            event(AuditEvent::record(
                action: $payment !== null ? AuditAction::Payment : AuditAction::Update,
                admin: $actor,
                description: ($payment !== null ? 'Underpayment paid' : 'Debt repayment recorded').' for payroll correction #'.$correction->id.' ('.PayrollMoney::format($amountCents).' USD).',
                entityType: PayrollCorrection::class,
                entityId: $correction->id,
                metadata: [
                    'event' => $payment !== null ? 'underpayment_paid' : 'debt_repayment_recorded',
                    'transaction_id' => $transaction->id,
                    'employee_id' => $correction->employee_id,
                    'period' => $correction->period,
                    'amount_cents' => $amountCents,
                    'outstanding_before_cents' => $before,
                    'outstanding_after_cents' => (int) $correction->outstanding_cents,
                    'employee_payment_id' => $payment?->id,
                    'transaction_date' => $date,
                    'note' => $note,
                ],
            ));

            return ['transaction' => $transaction, 'correction' => $correction->refresh(), 'replayed' => false];
        });
    }

    /** Super Admin pays underpayments; Super Admin and HR Manager manage debts. Anyone else is refused, whatever the screen offered. */
    public static function authorize(Admin $actor, string $type): void
    {
        $allowed = $type === PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT
            ? [AdminRole::SuperAdmin]
            : [AdminRole::SuperAdmin, AdminRole::HrManager];

        if (! in_array($actor->role, $allowed, true)) {
            throw new HttpResponseException(ApiResponse::error(
                $type === PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT ? 'Only a Super Admin can pay an underpayment.' : 'Only a Super Admin or an HR Manager can manage an employee debt.',
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
