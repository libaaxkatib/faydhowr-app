<?php

namespace App\Support\Hr\Payroll;

use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Builder;

/**
 * A payroll line keeps the employee, company and workplace NAMES as they were when the run was generated. When someone corrects a name
 * (a typo, "5GHOTEL" -> "5G HOTEL"), the lines of runs that are still OPEN (draft or reviewed) follow the correction, so the Payroll page and
 * its Excel never disagree with the rest of the panel. Approved and finalized months are closed records and are never edited: they keep the
 * names they were approved with. Only the label columns change - no amount, day count or status is touched, and the ids never change.
 */
final class OpenPayrollNames
{
    public static function employee(Employee $employee): int
    {
        return self::open()->where('employee_id', $employee->id)->update(['employee_name' => $employee->full_name]);
    }

    public static function company(ClientCompany $company): int
    {
        return self::open()
            ->whereHas('workAssignment', fn (Builder $assignment) => $assignment
                ->where('client_company_id', $company->id)
                ->orWhereHas('workLocation', fn (Builder $location) => $location->where('client_company_id', $company->id)))
            ->update(['company_name' => $company->name]);
    }

    public static function workplace(WorkLocation $location): int
    {
        return self::open()
            ->whereHas('workAssignment', fn (Builder $assignment) => $assignment->where('work_location_id', $location->id))
            ->update(['workplace_name' => $location->name]);
    }

    /** @return Builder<PayrollItem> */
    private static function open(): Builder
    {
        return PayrollItem::query()->whereHas('run', fn (Builder $run) => $run->whereIn('status', [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_REVIEWED]));
    }
}
