<?php

namespace App\Support\Hr\Payroll;

use App\Enums\AdminRole;
use App\Models\Admin;

/**
 * Who may CORRECT what - one place, enforced by the backend (the screens only hide what this refuses).
 *
 * Owner decision 2026-11-04: the HR Manager may correct draft, reviewed AND finalized payroll (assignment, salary and Temporary-cover corrections that
 * move a closed month's pay), correct a Temporary cover, and reopen a finalized company payroll. The Super Admin keeps everything the HR Manager has plus
 * the money operations below. Neither role can edit a closed month in place: every change to a closed month is a recorded correction / reconciliation
 * with a reason, a name and a time (ClosedPayrollCorrection, PayrollSectionReopening, PayrollWorkplaceReconciliation).
 *
 * Super Admin ONLY (not widened): paying an employee's underpayment, reversing such a payment and writing a debt off (RecordCorrectionTransactionAction)
 * - those move money to or from a person and stay with the highest role.
 */
final class CorrectionAuthority
{
    /** Roles that may record a correction of closed (approved / finalized) payroll. */
    public static function mayCorrectClosedPayroll(Admin $actor): bool
    {
        return in_array($actor->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true);
    }

    /** Roles that may reopen a FINALIZED company payroll (always with a reason; payments stay, see TransitionPayrollRunSectionAction). */
    public static function mayReopenFinalized(Admin $actor): bool
    {
        return in_array($actor->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true);
    }

    /** Roles that may record or void a reconciliation of an overpaid workplace. */
    public static function mayReconcile(Admin $actor): bool
    {
        return in_array($actor->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true);
    }

    /** Roles that may close or reopen a company / facility. */
    public static function mayCloseCompany(Admin $actor): bool
    {
        return in_array($actor->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true);
    }

    /** Money paid TO a person for an underpayment (and its reversal / a debt write-off): Super Admin only. */
    public static function mayPayUnderpayment(Admin $actor): bool
    {
        return $actor->role === AdminRole::SuperAdmin;
    }

    /** @return array<string, array{super_admin: bool, hr_manager: bool}> the matrix, for the permission report and the tests */
    public static function matrix(): array
    {
        $row = fn (callable $rule): array => [
            'super_admin' => $rule(self::as(AdminRole::SuperAdmin)),
            'hr_manager' => $rule(self::as(AdminRole::HrManager)),
        ];

        return [
            'correct_closed_payroll' => $row([self::class, 'mayCorrectClosedPayroll']),
            'reopen_finalized_company_payroll' => $row([self::class, 'mayReopenFinalized']),
            'reconcile_overpaid_workplace' => $row([self::class, 'mayReconcile']),
            'close_or_reopen_company' => $row([self::class, 'mayCloseCompany']),
            'pay_underpayment_to_employee' => $row([self::class, 'mayPayUnderpayment']),
        ];
    }

    private static function as(AdminRole $role): Admin
    {
        return (new Admin)->forceFill(['role' => $role]);
    }
}
