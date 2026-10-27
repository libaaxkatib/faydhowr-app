<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeDuplicateFinder;
use App\Support\Hr\EmploymentTypeChange;
use App\Support\Hr\Payroll\OpenPayrollNames;
use App\Support\Hr\Payroll\PaymentNumber;
use App\Support\Hr\UnresolvedTemporaryReplacements;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Edits an employee. Setting a phone / alternate phone that already belongs to
 * a DIFFERENT person (archived records included) is refused unless the caller
 * confirms it with a reason, so an edit cannot quietly create a duplicate
 * identity; the override is recorded in the audit trail.
 */
class UpdateEmployeeAction
{
    public function __construct(private readonly EmployeeDuplicateFinder $duplicates) {}

    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        $confirmed = (bool) ($data['confirm_duplicate'] ?? false);
        $reason = $data['duplicate_reason'] ?? null;
        $acceptMismatch = (bool) ($data['accept_payment_account_mismatch'] ?? false);
        $attributes = Arr::except($data, ['confirm_duplicate', 'duplicate_reason', 'accept_payment_account_mismatch']);

        // A payment number typed by HR is recorded as such: its source is "manual" and the old import check no longer describes it.
        // The registration phone, the employee number and the internal id are never touched by this.
        $paymentNumberChanged = array_key_exists('payment_account_number', $attributes)
            && PaymentNumber::clean($attributes['payment_account_number']) !== PaymentNumber::clean($employee->payment_account_number);

        if ($paymentNumberChanged) {
            $attributes['payment_account_number'] = PaymentNumber::clean($attributes['payment_account_number']);
            $attributes['payment_account_source'] = $attributes['payment_account_number'] === null ? null : PaymentNumber::SOURCE_MANUAL;
            $attributes['payment_account_source_ref'] = null;
            $attributes['payment_account_register_check'] = null;
        } elseif ($acceptMismatch && $employee->payment_account_register_check === PaymentNumber::CHECK_MISMATCH) {
            $attributes['payment_account_register_check'] = PaymentNumber::CHECK_ACCEPTED;
            $paymentNumberChanged = false;
        }

        $matches = collect();

        if (array_key_exists('phone', $attributes) || array_key_exists('alternate_phone', $attributes)) {
            $matches = $this->duplicates->find([
                'phone' => $attributes['phone'] ?? null,
                'alternate_phone' => $attributes['alternate_phone'] ?? null,
            ], $employee->id);
        }

        if ($matches->isNotEmpty() && ! $confirmed) {
            throw new HttpResponseException(ApiResponse::error(
                'Another employee already uses this phone number. Confirm it is intentional with a reason, or correct the number.',
                'DUPLICATE_EMPLOYEE_PHONE',
                422,
                ['matches' => $matches->all()],
            ));
        }

        $oldType = $employee->employment_type;
        $oldName = $employee->full_name;
        // Replacements of this employee that are unresolved (a Temporary worker confirmed as a Replacement) BEFORE this edit.
        $unresolvedBefore = $oldType === 'temporary' && array_key_exists('employment_type', $attributes)
            ? UnresolvedTemporaryReplacements::query()->where('employee_id', $employee->id)->get()
            : collect();

        // Permanent -> Temporary is refused while the person still holds an Active assignment (their pay line would vanish); the check and the save
        // happen under one lock so an assignment created at the same moment cannot slip in between.
        DB::transaction(function () use ($employee, $attributes): void {
            if (array_key_exists('employment_type', $attributes)) {
                Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
                EmploymentTypeChange::assertAllowed($employee, (string) $attributes['employment_type']);
            }

            $employee->update($attributes);
        });

        // A corrected name follows the lines of payroll runs that are still open (draft/reviewed); closed months keep the name they were approved with.
        $renamed = $employee->wasChanged('full_name');
        if ($renamed) {
            OpenPayrollNames::employee($employee);
        }

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: $renamed ? "Employee '{$oldName}' renamed to '{$employee->full_name}' ({$employee->employee_number})." : "Employee '{$employee->full_name}' ({$employee->employee_number}) updated.",
            entityType: Employee::class,
            entityId: $employee->id,
            metadata: [
                ...($matches->isEmpty() ? [] : [
                    'duplicate_override' => true,
                    'reason' => $reason,
                    'matched_employee_ids' => $matches->pluck('id')->all(),
                ]),
                ...($renamed ? ['full_name' => ['from' => $oldName, 'to' => $employee->full_name]] : []),
                ...($oldType !== $employee->employment_type ? ['employment_type' => ['from' => $oldType, 'to' => $employee->employment_type]] : []),
                // The payment number itself is never put in the audit trail (sensitive); only the fact of the change is.
                ...($paymentNumberChanged ? ['payment_account_number_changed' => true] : []),
                ...($acceptMismatch && $employee->payment_account_register_check === PaymentNumber::CHECK_ACCEPTED ? ['payment_account_mismatch_accepted' => true] : []),
            ] ?: null,
        ));

        // The correction of a Temporary worker who was confirmed as a Replacement: recorded on each assignment it resolves (who, when, how).
        foreach ($unresolvedBefore as $assignment) {
            if (! UnresolvedTemporaryReplacements::isUnresolved($assignment->refresh())) {
                UnresolvedTemporaryReplacements::auditResolved($assignment, 'employee_type_changed', $actor, ['employment_type_from' => $oldType, 'employment_type_to' => $employee->employment_type]);
            }
        }

        return $employee->load(['category', 'department', 'position']);
    }
}
