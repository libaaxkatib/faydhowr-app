<?php

namespace App\Actions\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Support\Hr\OfficeStaff\OfficeStaffEnrolment;
use Illuminate\Support\Facades\DB;

/**
 * Adds an EXISTING employee to Office Staff. The employee record is reused (never duplicated) and keeps its
 * employee number; missing contact, guarantor and position details are filled in, and existing ones are only
 * replaced when the caller confirmed that specific field (see OfficeStaffEnrolment).
 */
class AssignExistingEmployeeToOfficeStaffAction
{
    public function __construct(private readonly OfficeStaffEnrolment $enrolment) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Admin $actor): Employee
    {
        return DB::transaction(function () use ($data, $actor): Employee {
            $employee = Employee::query()->lockForUpdate()->findOrFail($data['employee_id']);

            $this->enrolment->enrol($employee, $data, $actor, false);

            return $employee->refresh();
        });
    }
}
