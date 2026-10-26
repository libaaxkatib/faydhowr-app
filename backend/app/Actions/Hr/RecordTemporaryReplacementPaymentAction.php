<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementPayment;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\TemporaryExpectedPayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Records a payment made to a Temporary employee for a cover. Payments are recorded when they actually happen (any time, including after the
 * cover ends) and are never blocked for differing from the calculation (docs/HR_PAYROLL_RULES.md N5): the system calculates what is expected,
 * the dialog shows it prefilled, and a different amount is a permitted manual override. What is stored is the calculated amount, the amount
 * actually paid, the optional override reason, the notes, the payment date and who recorded it, so the difference stays auditable.
 *
 * The expected amount is the balance still expected on the day of the payment: everything accrued by TemporaryExpectedPayment up to the payment
 * date (every calendar day the cover existed, N9-N11) minus what was already paid for this cover. Recording a payment approves nothing.
 */
class RecordTemporaryReplacementPaymentAction
{
    public function __construct(private readonly TemporaryExpectedPayment $expected = new TemporaryExpectedPayment) {}

    public function handle(TemporaryReplacement $replacement, array $data, Admin $actor): TemporaryReplacement
    {
        return $this->record($replacement, $data, $actor)['replacement'];
    }

    /**
     * @return array{replacement: TemporaryReplacement, check: array<string, mixed>}
     */
    public function record(TemporaryReplacement $replacement, array $data, Admin $actor): array
    {
        $check = $this->check($replacement, (string) $data['payment_date'], $data['amount']);

        $payment = TemporaryReplacementPayment::query()->create([
            'temporary_replacement_id' => $replacement->id,
            'payment_date' => $data['payment_date'],
            'amount' => $data['amount'],
            'calculated_amount' => $check['calculated_amount'],
            'notes' => $data['notes'] ?? null,
            'override_reason' => ($check['differs'] ?? false) ? ($data['override_reason'] ?? null) : null,
            'calculation' => $check['calculation'],
            'paid_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Payment of {$payment->amount} recorded for temporary replacement #{$replacement->id}.",
            entityType: TemporaryReplacement::class,
            entityId: $replacement->id,
            metadata: [
                'payment_id' => $payment->id,
                'amount' => (string) $payment->amount,
                'calculated_amount' => $check['calculated_amount'],
                'differs_from_calculated' => $check['differs'],
                'difference' => $check['difference'],
                'override_reason' => $payment->override_reason,
                'cost_approved' => $check['calculation']['approved'] ?? null,
            ],
        ));

        return [
            'replacement' => $replacement->load('workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'workAssignment.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy'),
            'check' => $check + ['payment_id' => $payment->id],
        ];
    }

    /**
     * What the system expected for this payment, compared with the amount entered. Never throws for a calculation problem: the payment is still
     * allowed (a manual amount), and the check says why nothing could be calculated.
     *
     * @return array{calculated_amount: ?string, entered_amount: string, differs: bool, difference: ?string, warning: ?string, calculation: array<string, mixed>}
     */
    public function check(TemporaryReplacement $replacement, string $paymentDate, mixed $amount): array
    {
        $entered = PayrollMoney::format(PayrollMoney::toCents($amount));

        try {
            $expected = $this->expected->forReplacement($replacement, CarbonImmutable::parse($paymentDate)->startOfDay());
        } catch (HttpResponseException $e) {
            return [
                'calculated_amount' => null,
                'entered_amount' => $entered,
                'differs' => false,
                'difference' => null,
                'warning' => 'The expected amount could not be calculated for this cover, so the entered amount is recorded as typed.',
                'calculation' => ['error' => $e->getResponse()->getData(true)['message'] ?? 'calculation failed', 'approved' => $replacement->approved_at !== null],
            ];
        }

        $paidBefore = PayrollMoney::toCents((string) $replacement->payments()->sum('amount'));
        $balance = max(0, $expected['expected_cents'] - $paidBefore);
        $enteredCents = PayrollMoney::toCents($amount);
        $differs = $enteredCents !== $balance;

        return [
            'calculated_amount' => PayrollMoney::format($balance),
            'entered_amount' => $entered,
            'differs' => $differs,
            'difference' => $differs ? PayrollMoney::format(abs($enteredCents - $balance)).($enteredCents > $balance ? ' more' : ' less') : null,
            'warning' => $differs
                ? "The amount entered ({$entered}) differs from the calculated amount (".PayrollMoney::format($balance).'). It will be recorded as entered, together with the calculated amount.'
                : null,
            'calculation' => [
                'from' => $expected['from'],
                'through' => $expected['through'],
                'counted_days' => $expected['counted_days'],
                'planned_end_date' => $expected['planned_end_date'],
                'actual_end_date' => $expected['actual_end_date'],
                'expected_total' => $expected['expected'],
                'paid_before' => PayrollMoney::format($paidBefore),
                'balance' => PayrollMoney::format($balance),
                'approved' => $expected['approved'],
                'permanent_deduction' => $expected['permanent_deduction'],
                'months' => array_map(fn (array $m): array => ['period' => $m['period'], 'days' => $m['days'], 'days_in_month' => $m['days_in_month'], 'amount' => $m['amount']], $expected['months']),
            ],
        ];
    }
}
