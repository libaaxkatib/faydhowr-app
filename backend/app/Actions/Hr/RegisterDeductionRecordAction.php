<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\DeductionKind;
use App\Support\Hr\Payroll\InputOwnership;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Registers a penalty or a one-month salary advance (Release 1B; owner decisions P-1, A-1, U-1, Q11).
 *
 *  - Idempotent: the same idempotency_key sent again returns the record already saved (replayed = true) instead of a second one; the key used with a
 *    different employee or different details is refused (409), never silently merged.
 *  - A duplicate guard: the same employee, company, month, date, amount and reason registered twice is refused (409 DUPLICATE_RECORD) unless the person
 *    confirms it is really a second one (confirm_duplicate). Different advances in one month are, and stay, allowed.
 *  - Company ownership and the payroll-month lock are checked exactly as before.
 *  - Q11 (Option B): an employee who is no longer Active (inactive or separated) may still have a record registered when an assignment of theirs
 *    overlapped that month - the same fact that produces their payroll line, which may not exist yet under per-company Generate.
 *  - Nothing is ever deleted or overwritten later: see ManageDeductionRecordAction.
 */
class RegisterDeductionRecordAction
{
    /** True when the last call returned an existing record instead of saving a new one. */
    public bool $replayed = false;

    /** @param  array<string, mixed>  $data  validated request data */
    public function handle(DeductionKind $kind, Employee $employee, array $data, Admin $actor): Model
    {
        $this->replayed = false;
        $key = $data['idempotency_key'] ?? null;
        $confirmDuplicate = (bool) ($data['confirm_duplicate'] ?? false);
        unset($data['idempotency_key'], $data['confirm_duplicate']);

        if ($key !== null && ($existing = $this->replay($kind, $employee, $key, $data)) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn (): Model => $this->register($kind, $employee, $data, $actor, $key, $confirmDuplicate));
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests raced: the other one saved the record first.
            if ($key !== null && ($existing = $this->replay($kind, $employee, $key, $data)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function register(DeductionKind $kind, Employee $employee, array $data, Admin $actor, ?string $key, bool $confirmDuplicate): Model
    {
        $period = $data['payroll_period'];
        $first = $period.'-01';
        $last = CarbonImmutable::parse($first)->endOfMonth()->toDateString();

        // Q11-B: a person who is no longer Active is still chargeable for a month they had an assignment in.
        if ($employee->status !== EmployeeStatus::Active && InputOwnership::keysOn($employee->id, $first, $last) === []) {
            throw new HttpResponseException(ApiResponse::error(
                "Employee '{$employee->full_name}' is {$employee->status->value} and had no assignment in {$period}, so a {$kind->label} cannot be recorded for that month. A {$kind->label} can be recorded for an Active employee, or for a former employee for a month they were assigned in.",
                'EMPLOYEE_NOT_ACTIVE',
                422,
            ));
        }

        // The record belongs to ONE company (each company owns its own payroll): the one the employee works for, or the one the person recording it chose.
        $company = InputOwnership::resolve($employee->id, $first, $last, array_key_exists('client_company_id', $data), $data['client_company_id'] ?? null);
        app(PayrollPeriodLock::class)->assertMonthEditable($period, 'The payroll month of this record', $employee->id, InputOwnership::key($company));

        if (! $confirmDuplicate && $this->duplicateOf($kind, $employee, $company, $data) !== null) {
            throw new HttpResponseException(ApiResponse::error(
                "The same {$kind->label} (same employee, company, month, date, amount and reason) is already recorded. If this is really a second one, confirm it; otherwise nothing needs to be done.",
                'DUPLICATE_RECORD',
                409,
            ));
        }

        $record = ($kind->model)::query()->create([
            ...$data,
            'currency' => strtoupper((string) $data['currency']),
            'client_company_id' => $company,
            'employee_id' => $employee->id,
            'recorded_by' => $actor->id,
            'recorded_by_role' => $actor->role->value,
            'idempotency_key' => $key,
            'status' => 'active',
            'version' => 1,
        ]);

        $amount = $record->{$kind->amountField};

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: ucfirst($kind->label)." of {$amount} {$record->currency} recorded for '{$employee->full_name}' ({$record->payroll_period}).",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: [
                'event' => "{$kind->key}_recorded",
                "{$kind->key}_id" => $record->id,
                'payroll_period' => $record->payroll_period,
                'client_company_id' => $record->client_company_id,
                'before' => null,
                'after' => $kind->snapshot($record),
                'recorded_by_role' => $actor->role->value,
            ],
        ));

        return $record;
    }

    /** @param  array<string, mixed>  $data */
    private function replay(DeductionKind $kind, Employee $employee, string $key, array $data): ?Model
    {
        $existing = ($kind->model)::query()->where('idempotency_key', $key)->first();

        if ($existing === null) {
            return null;
        }

        $same = (int) $existing->employee_id === (int) $employee->id
            && $existing->payroll_period === $data['payroll_period']
            && abs((float) $existing->{$kind->amountField} - (float) $data[$kind->amountField]) < 0.005
            && $existing->{$kind->dateField}?->toDateString() === CarbonImmutable::parse($data[$kind->dateField])->toDateString()
            && trim((string) $existing->reason) === trim((string) $data['reason']);

        if (! $same) {
            throw new HttpResponseException(ApiResponse::error(
                'This idempotency key was already used for a different record. Nothing was recorded; use a new key for a new record.',
                'IDEMPOTENCY_KEY_REUSED',
                409,
            ));
        }

        $this->replayed = true;

        return $existing;
    }

    /** @param  array<string, mixed>  $data */
    private function duplicateOf(DeductionKind $kind, Employee $employee, ?int $company, array $data): ?Model
    {
        return ($kind->model)::query()->active()
            ->where('employee_id', $employee->id)
            ->when($company === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $company))
            ->where('payroll_period', $data['payroll_period'])
            ->whereDate($kind->dateField, CarbonImmutable::parse($data[$kind->dateField])->toDateString())
            ->where($kind->amountField, $data[$kind->amountField])
            ->whereRaw('lower(trim(reason)) = ?', [mb_strtolower(trim((string) $data['reason']))])
            ->first();
    }
}
