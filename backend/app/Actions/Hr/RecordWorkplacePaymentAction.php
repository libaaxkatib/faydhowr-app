<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollWorkplacePayment;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityCalendar;
use App\Support\Hr\Payroll\PayrollApproval;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\WorkplacePayroll;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Records a payment HR made for a whole workplace in a payroll month - the full outstanding balance, or any part of it. It records; it moves no money.
 *
 * Only a finalized month can be paid (the rule of marking a single line paid). The run row is locked while this runs, so two payments of the same
 * workplace (or a workplace payment and a single-line payment) are decided one after the other against the true balance: a payment can never be larger
 * than what is still outstanding, and the balance of one workplace is never touched by a payment to another. The client's idempotency key turns a
 * retry or a double click into a no-op that returns the first payment. Payroll lines, salaries and assignments are not changed.
 */
class RecordWorkplacePaymentAction
{
    public const MODE_FULL = 'full';

    public const MODE_PARTIAL = 'partial';

    /**
     * @return array{payment: PayrollWorkplacePayment, summary: array<string, mixed>, replayed: bool}
     */
    public function handle(PayrollRun $run, ?int $companyId, ?int $locationId, string $mode, ?int $amountCents, string $paymentDate, ?string $note, string $idempotencyKey, Admin $actor): array
    {
        return DB::transaction(function () use ($run, $companyId, $locationId, $mode, $amountCents, $paymentDate, $note, $idempotencyKey, $actor): array {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);

            $existing = PayrollWorkplacePayment::query()->where('payroll_run_id', $run->id)->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if ($existing->client_company_id !== $companyId || $existing->work_location_id !== $locationId) {
                    $this->fail('This request key was already used for a different workplace. Nothing was recorded.', 'IDEMPOTENCY_KEY_REUSED', 409);
                }

                return ['payment' => $existing, 'summary' => WorkplacePayroll::summaryFor($run, $companyId, $locationId) ?? [], 'replayed' => true];
            }

            $summary = WorkplacePayroll::summaryFor($run, $companyId, $locationId);

            // Payments follow the company: only a workplace whose own company section is finalized (or, for a run from before company sections, a
            // finalized run) can be paid. A month that is partly finalized does not stop the companies that are finalized.
            if ($summary !== null && $summary['payable'] && ! $summary['can_record']) {
                $this->fail($run->sections()->exists()
                    ? 'This company\'s payroll is not finalized yet. Payments can only be recorded for a finalized company.'
                    : 'Payments can only be recorded for a finalized payroll month.', 'PAYROLL_RUN_NOT_FINALIZED');
            }

            if ($summary === null || ! $summary['payable']) {
                $this->fail($run->status !== PayrollRun::STATUS_FINALIZED && ! $run->sections()->exists()
                    ? 'Payments can only be recorded for a finalized payroll month.'
                    : 'This workplace has no payroll lines to pay in this month.', $run->status !== PayrollRun::STATUS_FINALIZED && ! $run->sections()->exists() ? 'PAYROLL_RUN_NOT_FINALIZED' : 'WORKPLACE_NOT_IN_PAYROLL');
            }

            $outstanding = (int) $summary['outstanding_cents'];

            if ($outstanding <= 0) {
                $this->fail('This workplace is already fully paid for this month. Nothing was recorded.', 'WORKPLACE_NOTHING_OUTSTANDING');
            }

            if ($mode === self::MODE_FULL) {
                $amountCents = $outstanding;
            } elseif ($amountCents === null || $amountCents <= 0) {
                $this->fail('The amount must be greater than zero.', 'AMOUNT_INVALID');
            } elseif ($amountCents > $outstanding) {
                $this->fail('The amount is more than what is still outstanding for this workplace ('.PayrollMoney::format($outstanding).' USD). Nothing was recorded.', 'AMOUNT_EXCEEDS_OUTSTANDING');
            }

            // Calendar dates compared as dates (Y-m-d sorts correctly): today is the Mogadishu day, so today's own date is allowed.
            if ($paymentDate > FacilityCalendar::today()->toDateString()) {
                $this->fail('The payment date cannot be in the future.', 'PAYMENT_DATE_IN_FUTURE');
            }

            // A payment that clears a company's last cent approves that company (and, if it was the last one, the month): PayrollApproval records it.
            $payment = PayrollApproval::track($run, fn (): PayrollWorkplacePayment => PayrollWorkplacePayment::query()->create([
                'payroll_run_id' => $run->id,
                'period' => $run->period,
                'client_company_id' => $companyId,
                'work_location_id' => $locationId,
                'amount_cents' => $amountCents,
                'payment_date' => $paymentDate,
                'note' => $note === null || trim($note) === '' ? null : trim($note),
                'idempotency_key' => $idempotencyKey,
                'recorded_by' => $actor->id,
            ]), $actor, ['source' => 'workplace_payment', 'mode' => $mode]);

            $after = WorkplacePayroll::summaryFor($run, $companyId, $locationId) ?? [];
            $name = trim(($summary['company_name'] ?? '').' '.($summary['workplace_name'] ?? ''));

            event(AuditEvent::record(
                action: AuditAction::Payment,
                admin: $actor,
                description: "Payroll {$run->period} workplace payment: ".PayrollMoney::format($amountCents)." USD for {$name} ({$mode}); outstanding ".PayrollMoney::format($outstanding).' -> '.PayrollMoney::format((int) ($after['outstanding_cents'] ?? 0)).' USD.',
                entityType: PayrollWorkplacePayment::class,
                entityId: $payment->id,
                metadata: [
                    'event' => 'workplace_payment_recorded',
                    'payroll_run_id' => $run->id,
                    'period' => $run->period,
                    'client_company_id' => $companyId,
                    'work_location_id' => $locationId,
                    'mode' => $mode,
                    'amount_cents' => $amountCents,
                    'outstanding_before_cents' => $outstanding,
                    'outstanding_after_cents' => (int) ($after['outstanding_cents'] ?? 0),
                    'status_after' => $after['status'] ?? null,
                    'payment_date' => $paymentDate,
                ],
            ));

            return ['payment' => $payment, 'summary' => $after, 'replayed' => false];
        });
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
