<?php

namespace Tests\Support;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeWorkAssignment;
use App\Models\Permission;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;

/**
 * Small builders for HR tests. Every record is fake test data created inside
 * the per-test in-memory database; nothing here touches a real database.
 */
trait HrFixtures
{
    protected function makeEmployee(string $status = 'active', string $employmentType = 'permanent', ?string $phone = null): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->unique()->name(),
            'phone' => $phone ?? fake()->unique()->numerify('61#######'),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => $status,
            'employment_type' => $employmentType,
            'application_date' => now()->toDateString(),
        ]);
    }

    /**
     * A person on the HR Waiting List, offered as the replacement of a Permanent position with the salary HR agrees
     * (docs/HR_PAYROLL_RULES.md N1, N3). The payload is the `successor` object of the request.
     *
     * @return array{type: string, employee_id: int, salary_amount: int, salary_currency: string}
     */
    protected function waitingSuccessor(int $monthlyDollars = 250): array
    {
        return ['type' => 'waiting_list', 'employee_id' => $this->makeEmployee('waiting')->id, 'salary_amount' => $monthlyDollars, 'salary_currency' => 'USD'];
    }

    /**
     * One replacement choice (a Waiting List person) for every open Permanent assignment of the employee: the `successors` of a separation request.
     *
     * @return list<array<string, mixed>>
     */
    protected function successorsFor(Employee|int $employee): array
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return EmployeeWorkAssignment::query()
            ->where('employee_id', $id)
            ->where('status', 'active')
            ->where('assignment_type', 'permanent')
            ->orderBy('id')
            ->get()
            ->map(fn (EmployeeWorkAssignment $a): array => ['assignment_id' => $a->id] + $this->waitingSuccessor())
            ->all();
    }

    protected function makeCompany(string $name = 'Test Company', string $category = 'company'): ClientCompany
    {
        return ClientCompany::query()->create(['name' => $name, 'category' => $category, 'status' => 'active']);
    }

    protected function makeClientLocation(ClientCompany $company, string $name = 'Main Site', ?int $capacity = null): WorkLocation
    {
        return WorkLocation::query()->create([
            'client_company_id' => $company->id,
            'location_type' => 'client',
            'name' => $name,
            'capacity' => $capacity,
            'status' => 'active',
        ]);
    }

    protected function makeAssignment(Employee $employee, ?ClientCompany $company, ?WorkLocation $location = null, int $monthlyDollars = 300, string $startDate = '2026-01-01'): EmployeeWorkAssignment
    {
        $assignment = EmployeeWorkAssignment::query()->create([
            'employee_id' => $employee->id,
            'work_location_id' => $location?->id,
            'client_company_id' => $company?->id ?? $location?->client_company_id,
            'start_date' => $startDate,
            'salary_amount' => $monthlyDollars,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
            'status' => 'active',
        ]);

        $assignment->salaryHistories()->create([
            'effective_from' => $startDate,
            'amount' => $monthlyDollars,
            'currency' => 'USD',
            'frequency' => 'monthly',
            'reason' => 'Test fixture',
        ]);

        return $assignment;
    }

    /**
     * A non-super admin holding exactly the given permission key(s).
     *
     * @param  list<string>  $permissionKeys
     */
    protected function tokenWithOnly(array $permissionKeys): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Sales]);

        foreach ($permissionKeys as $key) {
            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => Permission::query()->where('key', $key)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    /** A token for an admin of the given ROLE holding exactly the given direct permission grants. */
    protected function tokenForRoleWith(AdminRole $role, array $permissionKeys): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($permissionKeys as $key) {
            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => Permission::query()->where('key', $key)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    /** An admin whose role grants no HR access at all. */
    protected function noPermissionToken(): string
    {
        return Admin::factory()->create(['role' => AdminRole::Sales])->createToken('t')->plainTextToken;
    }

    protected function superAdminToken(): array
    {
        $admin = Admin::factory()->superAdmin()->create();

        return [$admin, $admin->createToken('t')->plainTextToken];
    }
}
