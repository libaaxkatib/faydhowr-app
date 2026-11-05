<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\CorrectionAuthority;
use App\Support\Hr\Payroll\DeductionBalances;
use App\Support\Hr\Payroll\DeductionKind;
use App\Support\Hr\Payroll\InputOwnership;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Void, correct or waive a penalty or a one-month advance (Release 1B; owner decisions R-1, P-1, A-1, Q8 Option A of 2026-11-05).
 *
 * Nothing is deleted or overwritten. A void leaves the row behind with status 'voided', who, when and why; a correction voids the old row and saves a
 * replacement that points back at it; a waive records how much of the balance a Super Admin forgave. Every change is checked against the version the
 * screen showed (409 RECORD_CHANGED when it was stale) and written to the audit log with the record before and after.
 *
 *  - NOT yet deducted by any finalized payroll: the HR Manager and the Super Admin may void or correct it (CorrectionAuthority::mayVoidOrCorrectDeduction).
 *  - Already deducted (even partly) by a finalized / approved company payroll: it cannot be voided or corrected in place (422 DEDUCTION_ALREADY_APPLIED).
 *    A closed month is changed only by reopening that company's payroll (N-6: a paid company only by a Super Admin), after which the amount is no longer
 *    "deducted" and the record can be handled; what is still owed after a partial deduction can be waived by a Super Admin.
 *  - A record whose deduction sits in a company payroll that was reopened after being finalized is a REVERSAL of an applied deduction: Super Admin only.
 *  - Waiving a balance is the Super Admin's alone; it never touches what was already deducted.
 */
class ManageDeductionRecordAction
{
    public function void(DeductionKind $kind, Employee $employee, int $recordId, string $reason, int $expectedVersion, Admin $actor): Model
    {
        return DB::transaction(function () use ($kind, $employee, $recordId, $reason, $expectedVersion, $actor): Model {
            $record = $this->lock($kind, $employee, $recordId, $expectedVersion);
            $this->assertMayChange($kind, $record, $actor);

            $before = $kind->snapshot($record);
            $this->markVoided($record, $reason, $actor);
            $this->audit($kind, $employee, $actor, 'voided', "Voided: {$reason}", $record, $before, $kind->snapshot($record), ['reason' => $reason]);

            return $record;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  validated fields to change (amount, date, reason, notes, payroll_period, client_company_id)
     * @return Model the NEW (replacement) record
     */
    public function correct(DeductionKind $kind, Employee $employee, int $recordId, array $changes, string $reason, int $expectedVersion, Admin $actor): Model
    {
        return DB::transaction(function () use ($kind, $employee, $recordId, $changes, $reason, $expectedVersion, $actor): Model {
            $old = $this->lock($kind, $employee, $recordId, $expectedVersion);
            $this->assertMayChange($kind, $old, $actor);

            $before = $kind->snapshot($old);
            $period = $changes['payroll_period'] ?? $old->payroll_period;
            $first = $period.'-01';
            $last = CarbonImmutable::parse($first)->endOfMonth()->toDateString();
            $company = array_key_exists('client_company_id', $changes)
                ? InputOwnership::resolve($employee->id, $first, $last, true, $changes['client_company_id'])
                : $old->client_company_id;

            // The month the corrected record falls in must be open too (the old one's month was checked by assertMayChange).
            app(PayrollPeriodLock::class)->assertMonthEditable($period, 'The payroll month of the corrected record', $employee->id, InputOwnership::key($company));

            $this->markVoided($old, "Corrected: {$reason}", $actor);

            $new = ($kind->model)::query()->create([
                'employee_id' => $employee->id,
                $kind->dateField => $changes[$kind->dateField] ?? $old->{$kind->dateField}?->toDateString(),
                $kind->amountField => $changes[$kind->amountField] ?? $old->{$kind->amountField},
                'reason' => $changes['reason'] ?? $old->reason,
                'notes' => array_key_exists('notes', $changes) ? $changes['notes'] : $old->notes,
                'currency' => $old->currency,
                'payroll_period' => $period,
                'client_company_id' => $company,
                'recorded_by' => $actor->id,
                'recorded_by_role' => $actor->role->value,
                'status' => 'active',
                'version' => 1,
                'replaces_id' => $old->id,
            ]);

            $this->audit($kind, $employee, $actor, 'corrected', "Corrected: {$reason}", $new, $before, $kind->snapshot($new), ['reason' => $reason, 'replaces_id' => $old->id]);

            return $new;
        });
    }

    /** @param  int|null  $amountCents  how much to waive (null = everything still owed) */
    public function waive(DeductionKind $kind, Employee $employee, int $recordId, ?int $amountCents, string $reason, int $expectedVersion, Admin $actor): Model
    {
        return DB::transaction(function () use ($kind, $employee, $recordId, $amountCents, $reason, $expectedVersion, $actor): Model {
            $record = $this->lock($kind, $employee, $recordId, $expectedVersion);

            if (! CorrectionAuthority::mayReverseOrWaiveDeduction($actor)) {
                $this->fail('Only a Super Admin can waive what is still owed on a '.$kind->label.'. Nothing was changed.', 'WAIVE_SUPER_ADMIN_ONLY', 403);
            }

            $deducted = DeductionBalances::deducted($kind->sourceType, [(int) $record->id])[(int) $record->id] ?? 0;
            $owed = DeductionBalances::owed($record, $deducted);

            if ($deducted === 0) {
                $this->fail('Nothing of this '.$kind->label.' was ever deducted, so there is no balance to waive: void it instead. Nothing was changed.', 'WAIVE_NOT_APPLICABLE', 422);
            }

            if ($owed === 0) {
                $this->fail('This '.$kind->label.' has been deducted in full; there is no balance to waive. Nothing was changed.', 'WAIVE_NOT_APPLICABLE', 422);
            }

            $amount = $amountCents ?? $owed;

            if ($amount < 1 || $amount > $owed) {
                $this->fail('The amount to waive must be between 0.01 and what is still owed ('.number_format($owed / 100, 2, '.', '').'). Nothing was changed.', 'WAIVE_AMOUNT_INVALID', 422);
            }

            $before = $kind->snapshot($record);
            $record->forceFill([
                'waived_cents' => (int) $record->waived_cents + $amount,
                'waived_at' => now(), 'waived_by' => $actor->id, 'waive_reason' => $reason,
                'version' => $record->version + 1,
            ])->save();

            $this->audit($kind, $employee, $actor, 'waived', 'Waived '.number_format($amount / 100, 2, '.', '').": {$reason}", $record, $before, $kind->snapshot($record), ['reason' => $reason, 'waived_cents' => $amount, 'owed_before_cents' => $owed]);

            return $record;
        });
    }

    private function lock(DeductionKind $kind, Employee $employee, int $recordId, int $expectedVersion): Model
    {
        $record = ($kind->model)::query()->where('employee_id', $employee->id)->lockForUpdate()->find($recordId);

        if ($record === null) {
            $this->fail('This '.$kind->label.' does not exist for this employee.', 'RECORD_NOT_FOUND', 404);
        }

        if ((int) $record->version !== $expectedVersion) {
            $this->fail('This '.$kind->label.' was changed by someone else while this screen was open. Nothing was changed; refresh and try again.', 'RECORD_CHANGED', 409);
        }

        if ($record->status !== 'active') {
            $this->fail('This '.$kind->label.' is already voided; nothing more can be done with it. Nothing was changed.', 'RECORD_NOT_ACTIVE', 422);
        }

        return $record;
    }

    /** Who may change a record that no finalized payroll has deducted, and whether the month is still open. */
    private function assertMayChange(DeductionKind $kind, Model $record, Admin $actor): void
    {
        if (! CorrectionAuthority::mayVoidOrCorrectDeduction($actor)) {
            $this->fail('Only an HR Manager or a Super Admin can void or correct a '.$kind->label.'. Nothing was changed.', 'DEDUCTION_CHANGE_FORBIDDEN', 403);
        }

        $deducted = DeductionBalances::deducted($kind->sourceType, [(int) $record->id])[(int) $record->id] ?? 0;

        if ($deducted > 0) {
            $this->fail(
                'This '.$kind->label.' was already deducted ('.number_format($deducted / 100, 2, '.', '').') by a finalized company payroll, so it cannot be changed in place. Reopen that company\'s payroll for correction (a company that has payments recorded can only be reopened by a Super Admin), or ask a Super Admin to waive what is still owed. Nothing was changed.',
                'DEDUCTION_ALREADY_APPLIED',
                422,
            );
        }

        app(PayrollPeriodLock::class)->assertMonthEditable($record->payroll_period, 'The payroll month of this record', (int) $record->employee_id, InputOwnership::key($record->client_company_id));

        // The deduction once sat in a company payroll that was finalized and then reopened: changing the record now reverses an applied deduction.
        if (! CorrectionAuthority::mayReverseOrWaiveDeduction($actor) && $this->wasAppliedInReopenedPayroll($kind, (int) $record->id)) {
            $this->fail('This '.$kind->label.' was deducted in a company payroll that was finalized and then reopened; reversing it is for a Super Admin. Nothing was changed.', 'REVERSE_APPLIED_SUPER_ADMIN_ONLY', 403);
        }
    }

    private function wasAppliedInReopenedPayroll(DeductionKind $kind, int $recordId): bool
    {
        return DB::table('payroll_item_deductions as d')
            ->join('payroll_items as i', 'i.id', '=', 'd.payroll_item_id')
            ->join('payroll_section_reopenings as ro', 'ro.payroll_run_section_id', '=', 'i.section_id')
            ->where('d.source_type', $kind->sourceType)->where('d.source_id', $recordId)->where('d.amount_cents', '>', 0)
            ->exists();
    }

    private function markVoided(Model $record, string $reason, Admin $actor): void
    {
        $record->forceFill([
            'status' => 'voided', 'voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason,
            'version' => $record->version + 1,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $extra
     */
    private function audit(DeductionKind $kind, Employee $employee, Admin $actor, string $event, string $text, Model $record, array $before, array $after, array $extra): void
    {
        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: ucfirst($kind->label)." #{$record->id} for '{$employee->full_name}' ({$record->payroll_period}) {$event}. {$text}",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: [
                'event' => "{$kind->key}_{$event}",
                "{$kind->key}_id" => $record->id,
                'payroll_period' => $record->payroll_period,
                'client_company_id' => $record->client_company_id,
                'before' => $before,
                'after' => $after,
                'actor_role' => $actor->role->value,
                ...$extra,
            ],
        ));
    }

    private function fail(string $message, string $code, int $status): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
