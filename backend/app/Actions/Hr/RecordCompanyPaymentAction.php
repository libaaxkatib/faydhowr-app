<?php

namespace App\Actions\Hr;

use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\PayrollWorkplacePayment;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollApproval;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\WorkplacePayroll;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * "Approve Paid" / "Approve Partial" for ONE finalized company (owner decision 2026-11-04). It records the payment in the existing payment records
 * (payroll_workplace_payments: date, who, company, month, amount, note, audit event) through RecordWorkplacePaymentAction - nothing new is stored and no
 * payroll line, salary or finalized amount is touched.
 *
 *   full    pays the company's WHOLE outstanding balance;
 *   partial pays the amount given (more than zero, at most the outstanding balance) - the company stays "Partially paid" and is NOT approved.
 *
 * When the payments reach the company's total, PayrollApproval approves the company by itself (an audit event says so) and, when it was the last one,
 * the month. A company is normally one workplace; a company with several pays them in name order, each up to its own balance, so a workplace is never
 * paid more than it is owed. The whole request is one transaction with the run locked, so two simultaneous requests are decided one after the other
 * against the true balance (no overpayment), and the client's key turns a retry or a double click into a no-op (no duplicate record).
 */
class RecordCompanyPaymentAction
{
    /**
     * @return array{payments: list<PayrollWorkplacePayment>, replayed: bool}
     */
    public function handle(PayrollRunSection $section, string $mode, ?int $amountCents, string $paymentDate, ?string $note, string $idempotencyKey, Admin $actor): array
    {
        return DB::transaction(function () use ($section, $mode, $amountCents, $paymentDate, $note, $idempotencyKey, $actor): array {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($section->payroll_run_id);
            $section = PayrollRunSection::query()->lockForUpdate()->findOrFail($section->id);
            $companyId = $section->client_company_id === null ? null : (int) $section->client_company_id;
            // One key per workplace payment, all made from the client's key (a payment key holds 64 characters at most).
            $base = 'co-'.substr(sha1($idempotencyKey), 0, 40);

            $existing = PayrollWorkplacePayment::query()->where('payroll_run_id', $run->id)->where('idempotency_key', 'like', $base.'-%')->orderBy('id')->get();

            if ($existing->isNotEmpty()) {
                if ($existing->contains(fn (PayrollWorkplacePayment $p): bool => $p->client_company_id !== $companyId)) {
                    $this->fail('This request key was already used for a different company. Nothing was recorded.', 'IDEMPOTENCY_KEY_REUSED', 409);
                }

                return ['payments' => $existing->all(), 'replayed' => true];
            }

            if ($section->status !== PayrollRunSection::STATUS_FINALIZED) {
                $this->fail('This company\'s payroll is not finalized yet. Payments can only be recorded for a finalized company.', 'PAYROLL_RUN_NOT_FINALIZED');
            }

            if ($companyId === null) {
                $this->fail('These lines name no company, so they can only be paid line by line.', 'WORKPLACE_NOT_IN_PAYROLL');
            }

            $workplaces = array_values(array_filter(
                WorkplacePayroll::summaries($run),
                fn (array $w): bool => $w['payable'] && $w['client_company_id'] === $companyId && $w['can_record'] && $w['outstanding_cents'] > 0,
            ));
            $outstanding = (int) array_sum(array_column($workplaces, 'outstanding_cents'));

            if ($outstanding <= 0) {
                $this->fail('This company is already fully paid for this month. Nothing was recorded.', 'WORKPLACE_NOTHING_OUTSTANDING');
            }

            if ($mode === RecordWorkplacePaymentAction::MODE_FULL) {
                $amountCents = $outstanding;
            } elseif ($amountCents === null || $amountCents <= 0) {
                $this->fail('The amount must be greater than zero.', 'AMOUNT_INVALID');
            } elseif ($amountCents > $outstanding) {
                $this->fail('The amount is more than what is still outstanding for this company ('.PayrollMoney::format($outstanding).' USD). Nothing was recorded.', 'AMOUNT_EXCEEDS_OUTSTANDING');
            }

            $left = $amountCents;
            $payments = [];
            $record = app(RecordWorkplacePaymentAction::class);

            foreach ($workplaces as $index => $workplace) {
                if ($left <= 0) {
                    break;
                }

                $take = min($left, (int) $workplace['outstanding_cents']);
                $result = $record->handle(
                    $run,
                    $companyId,
                    $workplace['work_location_id'],
                    $take === (int) $workplace['outstanding_cents'] && $mode === RecordWorkplacePaymentAction::MODE_FULL ? RecordWorkplacePaymentAction::MODE_FULL : RecordWorkplacePaymentAction::MODE_PARTIAL,
                    $take,
                    $paymentDate,
                    $note,
                    $base.'-'.$index,
                    $actor,
                );
                $payments[] = $result['payment'];
                $left -= $take;
            }

            return ['payments' => $payments, 'replayed' => false];
        });
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
