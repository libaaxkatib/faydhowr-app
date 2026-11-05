<?php

namespace App\Actions\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Support\Hr\Payroll\DeductionKind;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 5 "Salary Advance": a manual ledger, no approval workflow, no installment engine - a row's existence IS the official
 * advance record. Several advances in one month are allowed. Since Release 1B the registration is idempotent, guarded against exact duplicates, open to a
 * former employee for a month they were assigned in, and payroll deducts it only up to the net of $0 (what is left stays owed and carries to the next
 * month): see RegisterDeductionRecordAction and DeductionBalances.
 */
class RecordEmployeeAdvanceAction
{
    /** True when the request was a repeat of one already saved (nothing new was recorded). */
    public bool $replayed = false;

    public function handle(Employee $employee, array $data, Admin $actor): Employee
    {
        $register = app(RegisterDeductionRecordAction::class);
        $register->handle(DeductionKind::of('advance'), $employee, $data, $actor);
        $this->replayed = $register->replayed;

        return $employee->load('advances.recordedBy', 'advances.clientCompany');
    }
}
