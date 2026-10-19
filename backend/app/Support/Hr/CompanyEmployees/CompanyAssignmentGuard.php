<?php

namespace App\Support\Hr\CompanyEmployees;

use App\Enums\EmployeeStatus;
use App\Enums\WorkAssignmentStatus;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared server-side checks of the Company Employee Management workflow. Everything a request names - the company, the
 * assignment, the employee - is re-checked here; nothing is trusted because the screen only offered it.
 */
final class CompanyAssignmentGuard
{
    /**
     * An assignment that belongs to another company answers exactly like one that does not exist, so ids of other companies'
     * assignments cannot be probed or changed through this company's URL.
     */
    public static function assignmentOf(ClientCompany $company, EmployeeWorkAssignment $assignment): EmployeeWorkAssignment
    {
        if ((int) $assignment->client_company_id !== (int) $company->id) {
            throw (new ModelNotFoundException)->setModel(EmployeeWorkAssignment::class, [$assignment->getKey()]);
        }

        return $assignment;
    }

    public static function requireOpen(EmployeeWorkAssignment $assignment): void
    {
        if ($assignment->status !== WorkAssignmentStatus::Active) {
            self::fail('Only an active assignment can be changed this way. This one is '.$assignment->status->value.'.', 'ASSIGNMENT_NOT_ACTIVE');
        }
    }

    /** Only an Active employee of the HR register can be placed; anyone else must first be activated in HR. */
    public static function requireAssignable(Employee $employee): void
    {
        if ($employee->trashed()) {
            self::fail('This employee record is archived and cannot be assigned.', 'EMPLOYEE_ARCHIVED');
        }

        if ($employee->status !== EmployeeStatus::Active) {
            self::fail("Employee {$employee->employee_number} is {$employee->status->value} in HR. Only an Active employee can be assigned to a company; complete the HR steps first.", 'EMPLOYEE_NOT_ACTIVE');
        }
    }

    public static function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value)->startOfDay();
    }

    public static function fail(string $message, string $code, ?array $errors = null): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422, $errors));
    }
}
