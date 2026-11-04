<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use Closure;

/**
 * Approval of a payroll month IS payment (owner decision 2026-11-04). Nothing is stored for it and nobody presses an Approve button:
 *
 *   company  - Approved  = its payroll is FINALIZED and nothing is outstanding (every cent paid, by workplace payments and/or lines paid one by one);
 *              Partially paid = something paid, a balance remains (NOT approved);  Unpaid = nothing paid (NOT approved).
 *   month    - Approved  = it has companies and EVERY one of them is Approved. One company with a balance keeps the whole month pending.
 *
 * Reviewing or finalizing a company never approves it, and neither changes any payroll amount: finalized payroll is only ever READ here. Because the
 * state is derived from the payment records (never overwritten), voiding a payment withdraws the approval by itself. What is recorded is the moment it
 * flips: track() compares the state before and after a money change and writes an audit event for every company / month that became (or stopped being)
 * approved, with who did it.
 *
 * A finalized company with nothing payable (no line with a positive net) has nothing outstanding, so it counts as Approved once finalized.
 */
final class PayrollApproval
{
    public const UNPAID = 'unpaid';

    public const PARTIAL = 'partially_paid';

    public const APPROVED = 'approved';

    /**
     * The payment / approval state of every company section of the run.
     *
     * @return list<array<string, mixed>>
     */
    public static function sections(PayrollRun $run): array
    {
        $sections = $run->sections()->with('clientCompany')->get();

        if ($sections->isEmpty()) {
            return [];
        }

        $totals = WorkplacePayroll::companyTotals($run);

        return $sections->map(function (PayrollRunSection $s) use ($totals): array {
            $t = $totals[$s->client_company_id === null ? 'none' : (string) $s->client_company_id] ?? ['total_cents' => 0, 'paid_cents' => 0, 'outstanding_cents' => 0];
            $finalized = $s->status === PayrollRunSection::STATUS_FINALIZED;
            $total = (int) $t['total_cents'];
            $paid = (int) $t['paid_cents'];
            $outstanding = (int) $t['outstanding_cents'];

            return [
                'section_id' => $s->id,
                'client_company_id' => $s->client_company_id === null ? null : (int) $s->client_company_id,
                'company_name' => $s->clientCompany?->name,
                'finalized' => $finalized,
                'total_cents' => $total,
                'paid_cents' => $paid,
                'outstanding_cents' => $outstanding,
                'payment_status' => match (true) {
                    $finalized && $outstanding <= 0 => self::APPROVED,
                    $paid > 0 => self::PARTIAL,
                    default => self::UNPAID,
                },
                'approved' => $finalized && $outstanding <= 0,
                // Money can be recorded only for a finalized company that still owes something.
                'can_pay' => $finalized && $outstanding > 0,
            ];
        })->all();
    }

    /**
     * The month's counts and amounts, and whether it is approved.
     *
     * @param  list<array<string, mixed>>|null  $sections  the result of sections() when the caller has it already
     * @return array<string, mixed>
     */
    public static function month(PayrollRun $run, ?array $sections = null): array
    {
        $sections ??= self::sections($run);
        $count = fn (string $status): int => count(array_filter($sections, fn (array $s): bool => $s['payment_status'] === $status));
        $notFinalized = count(array_filter($sections, fn (array $s): bool => ! $s['finalized']));
        $total = count($sections);
        $approved = $count(self::APPROVED);

        return [
            'total_companies' => $total,
            'approved_companies' => $approved,
            // Finalized, nothing paid yet.
            'unpaid_companies' => count(array_filter($sections, fn (array $s): bool => $s['finalized'] && $s['payment_status'] === self::UNPAID)),
            'partially_paid_companies' => $count(self::PARTIAL),
            // Not finalized yet: cannot be paid, so cannot be approved.
            'not_finalized_companies' => $notFinalized,
            'total_cents' => (int) array_sum(array_column($sections, 'total_cents')),
            'paid_cents' => (int) array_sum(array_column($sections, 'paid_cents')),
            'outstanding_cents' => (int) array_sum(array_column($sections, 'outstanding_cents')),
            'fully_approved' => $total > 0 && $approved === $total,
        ];
    }

    /**
     * Runs a money change and records, in the audit log, every company and the month whose approval it started or ended.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @param  array<string, mixed>  $context  what caused it (the payment id, the kind of payment)
     * @return T
     */
    public static function track(PayrollRun $run, Closure $change, Admin $actor, array $context = []): mixed
    {
        $before = self::sections($run);
        $monthBefore = self::month($run, $before)['fully_approved'];
        $result = $change();
        $after = self::sections($run);
        $monthAfter = self::month($run, $after)['fully_approved'];

        $was = array_column($before, 'approved', 'section_id');

        foreach ($after as $section) {
            $wasApproved = (bool) ($was[$section['section_id']] ?? false);

            if ($wasApproved === $section['approved']) {
                continue;
            }

            $name = $section['company_name'] ?? 'No company';

            event(AuditEvent::record(
                action: $section['approved'] ? AuditAction::Approve : AuditAction::Update,
                admin: $actor,
                description: $section['approved']
                    ? "Payroll {$run->period} for {$name} approved automatically: fully paid (".PayrollMoney::format($section['paid_cents']).' USD).'
                    : "Payroll {$run->period} for {$name} is no longer approved: a payment was voided (".PayrollMoney::format($section['outstanding_cents']).' USD outstanding).',
                entityType: PayrollRunSection::class,
                entityId: $section['section_id'],
                metadata: [
                    'event' => $section['approved'] ? 'payroll_company_approved' : 'payroll_company_approval_withdrawn',
                    'payroll_run_id' => $run->id,
                    'period' => $run->period,
                    'client_company_id' => $section['client_company_id'],
                    'total_cents' => $section['total_cents'],
                    'paid_cents' => $section['paid_cents'],
                    'outstanding_cents' => $section['outstanding_cents'],
                    ...$context,
                ],
            ));
        }

        if ($monthBefore !== $monthAfter) {
            event(AuditEvent::record(
                action: $monthAfter ? AuditAction::Approve : AuditAction::Update,
                admin: $actor,
                description: $monthAfter
                    ? "Payroll {$run->period} approved automatically: every company is fully paid."
                    : "Payroll {$run->period} is no longer approved: a company has an outstanding balance again.",
                entityType: PayrollRun::class,
                entityId: $run->id,
                metadata: ['event' => $monthAfter ? 'payroll_month_approved' : 'payroll_month_approval_withdrawn', 'period' => $run->period, ...self::month($run, $after), ...$context],
            ));
        }

        return $result;
    }
}
