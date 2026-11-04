<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollCorrection;
use App\Models\PayrollRecoveryDeduction;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * P1 (owner decision 2026-11-04). A company's payroll for a month that is reopened and rebuilt makes the OPEN corrections of that company and month
 * obsolete: they were recording a difference between the stored (closed) lines and what the lines should have been, and the rebuilt lines now contain it.
 * Left open they count the same pay twice (reproduced: a regular line of 150.00 AND an open underpayment of 150.00).
 *
 *  - supersede(): the open corrections of that company and month become `superseded` (kept, with who / when / why / which section), audited one by one.
 *    A superseded correction is ignored by overpayment recovery and by the debt / underpayment totals.
 *  - assertCanSupersede(): refused (nothing changed) when money already moved for such a correction - a payment of an underpayment, a repayment or a write-off, or a
 *    recovery that was applied to a later month. That trail must not be cut by a reopening.
 *  - assertRegenerated(): a reopened company cannot be finalized again until the month has been generated again AFTER the reopening, otherwise the old lines would
 *    be finalized again while the corrections that covered the difference are gone (the pay would be lost instead of double counted).
 */
final class CorrectionSupersession
{
    /** @return Collection<int, PayrollCorrection> */
    public static function openFor(PayrollRunSection $section, string $period): Collection
    {
        return PayrollCorrection::query()
            ->where('period', $period)
            ->where('status', PayrollCorrection::STATUS_OPEN)
            ->when($section->client_company_id === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $section->client_company_id))
            ->orderBy('id')
            ->get();
    }

    public static function assertCanSupersede(PayrollRunSection $section, string $period): void
    {
        $blocked = self::openFor($section, $period)->filter(
            fn (PayrollCorrection $c): bool => $c->transactions()->exists() || $c->recoveries()->where('status', PayrollRecoveryDeduction::STATUS_APPLIED)->exists(),
        );

        if ($blocked->isNotEmpty()) {
            throw new HttpResponseException(ApiResponse::error(
                'This company has payroll corrections for '.$period.' with money already paid, repaid, recovered or written off. They cannot be superseded by a reopening; settle them first. Nothing was changed.',
                'CORRECTION_HAS_MONEY_MOVEMENT',
                422,
                ['correction_ids' => $blocked->pluck('id')->values()->all()],
            ));
        }
    }

    /** @return int how many corrections were superseded */
    public static function supersede(PayrollRunSection $section, string $period, Admin $actor, string $reason): int
    {
        $count = 0;

        foreach (self::openFor($section, $period) as $correction) {
            $before = ['status' => $correction->status, 'outstanding_cents' => (int) $correction->outstanding_cents];
            $correction->forceFill([
                'status' => PayrollCorrection::STATUS_SUPERSEDED,
                'superseded_at' => now(),
                'superseded_by' => $actor->id,
                'supersede_reason' => mb_substr($reason, 0, 500),
                'superseded_section_id' => $section->id,
            ])->save();

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Payroll correction #{$correction->id} ({$correction->kind}, {$period}) superseded because the company's payroll was reopened and is rebuilt: {$reason}",
                entityType: PayrollCorrection::class,
                entityId: $correction->id,
                metadata: [
                    'event' => 'payroll_correction_superseded',
                    'before' => $before,
                    'after' => ['status' => PayrollCorrection::STATUS_SUPERSEDED, 'outstanding_cents' => (int) $correction->outstanding_cents],
                    'period' => $period,
                    'client_company_id' => $section->client_company_id,
                    'payroll_run_section_id' => $section->id,
                    'reason' => $reason,
                    'superseded_by_admin_id' => $actor->id,
                    'superseded_by_role' => $actor->role->value,
                    'note' => 'kept in the history; ignored by recovery and by the debt / underpayment totals',
                ],
            ));
            $count++;
        }

        return $count;
    }

    /** A reopened company whose corrections were superseded must be generated again before it can be finalized. */
    public static function assertRegenerated(PayrollRunSection $section, PayrollRun $run): void
    {
        $latest = PayrollCorrection::query()->where('superseded_section_id', $section->id)->where('period', $run->period)->max('superseded_at');

        if ($latest === null) {
            return;
        }

        $generatedAt = $run->generated_at;

        if ($generatedAt === null || $generatedAt->lessThanOrEqualTo(Carbon::parse($latest))) {
            throw new HttpResponseException(ApiResponse::error(
                'This company\'s payroll was reopened and its payroll corrections were superseded. Generate the month again, review it, and only then finalize it. Nothing was changed.',
                'REGENERATE_AFTER_REOPEN',
                422,
            ));
        }
    }
}
