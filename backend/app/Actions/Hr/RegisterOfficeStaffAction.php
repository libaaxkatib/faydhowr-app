<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeStatusHistory;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeCodeGenerator;
use App\Support\Hr\EmployeeDuplicateFinder;
use App\Support\Hr\OfficeStaff\OfficeStaffEnrolment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Registers a NEW person directly as Office Staff. Office Staff are hired, not recruited, so the employee is
 * created Active and never passes through Applicant, Waiting or Practical/Training; the status history records
 * that this was a direct registration. The employee number comes from the application's own generator.
 *
 * The same duplicate protection as normal registration applies: a phone that already belongs to an employee
 * (archived ones included) blocks creation unless the caller confirms it is a different person with a reason.
 * Everything - employee, history, emergency contact, guarantor and the office assignment with its salary - is
 * one transaction, so a refused assignment (for example office capacity) leaves no half-registered employee.
 */
class RegisterOfficeStaffAction
{
    public function __construct(
        private readonly EmployeeCodeGenerator $codes,
        private readonly EmployeeDuplicateFinder $duplicates,
        private readonly OfficeStaffEnrolment $enrolment,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Admin $actor): Employee
    {
        // Never fall back to some other category: if the migration that adds it has not run, say so.
        $category = EmployeeCategory::officeStaff();

        if ($category === null) {
            throw new HttpResponseException(ApiResponse::error(
                'The "Office Staff" employee category is not set up yet. Run the latest database migrations first.',
                'OFFICE_STAFF_CATEGORY_MISSING',
                422,
            ));
        }

        $confirmed = (bool) ($data['confirm_duplicate'] ?? false);

        $matches = $this->duplicates->find([
            'phone' => $data['phone'],
            'alternate_phone' => $data['alternate_phone'] ?? null,
            'full_name' => $data['full_name'],
        ]);
        $phoneMatches = $matches->filter(fn (array $m): bool => in_array(EmployeeDuplicateFinder::MATCH_PHONE, $m['matched_on'], true));

        if ($phoneMatches->isNotEmpty() && ! $confirmed) {
            throw new HttpResponseException(ApiResponse::error(
                'An employee with this phone number already exists. Add the existing employee to Office Staff instead, or confirm this is a different person with a reason.',
                'DUPLICATE_EMPLOYEE_PHONE',
                422,
                ['matches' => $phoneMatches->values()->all()],
            ));
        }

        return DB::transaction(function () use ($data, $actor, $matches, $phoneMatches, $category): Employee {
            $employee = Employee::query()->create([
                'employee_number' => $this->codes->next(),
                'full_name' => trim($data['full_name']),
                'phone' => trim($data['phone']),
                'alternate_phone' => $data['alternate_phone'] ?? null,
                'gender' => $data['gender'] ?? null,
                'location' => $data['location'] ?? null,
                'employee_category_id' => $category->id,
                'status' => EmployeeStatus::Active,
                'employment_type' => 'permanent',
                'application_date' => CarbonImmutable::now()->toDateString(),
                'joining_date' => $data['start_date'],
                'source' => 'Office Staff registration',
                'created_by' => $actor->id,
            ]);

            EmployeeStatusHistory::query()->create([
                'employee_id' => $employee->id,
                'from_status' => null,
                'to_status' => EmployeeStatus::Active,
                'changed_by' => $actor->id,
                'note' => 'Registered directly as Office Staff; no recruitment pipeline stages apply.',
                'source' => 'office_staff',
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Employee '{$employee->full_name}' registered as Office Staff ({$employee->employee_number}).",
                entityType: Employee::class,
                entityId: $employee->id,
                metadata: $matches->isEmpty() ? null : [
                    'duplicate_override' => $phoneMatches->isNotEmpty(),
                    'reason' => $data['duplicate_reason'] ?? null,
                    'matched_employee_ids' => $matches->pluck('id')->all(),
                ],
            ));

            $this->enrolment->enrol($employee, $data, $actor, true);

            return $employee->refresh();
        });
    }
}
