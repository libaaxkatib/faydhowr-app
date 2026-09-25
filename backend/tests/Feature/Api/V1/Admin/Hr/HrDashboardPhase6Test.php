<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrDashboardPhase6Test extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(string $status = 'active'): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => $status,
            'application_date' => now()->toDateString(),
        ]);
    }

    private function makeClientLocation(): WorkLocation
    {
        $company = ClientCompany::query()->create(['name' => fake()->unique()->company(), 'status' => 'active']);

        return WorkLocation::query()->create([
            'client_company_id' => $company->id,
            'location_type' => 'client',
            'name' => 'Site '.fake()->unique()->numerify('###'),
            'status' => 'active',
        ]);
    }

    public function test_dashboard_reports_present_absent_late_split_for_today(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $present = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$present->id}/work-assignments", [
            'work_location_id' => $office->id, 'start_date' => now()->toDateString(),
            'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$present->id}/attendance", [
            'date' => now()->toDateString(), 'status' => 'present',
        ])->assertOk();

        $absent = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$absent->id}/attendance", [
            'date' => now()->toDateString(), 'status' => 'absent',
        ])->assertOk();

        $late = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$late->id}/attendance", [
            'date' => now()->toDateString(), 'status' => 'late',
        ])->assertOk();

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk();

        $this->assertSame(1, $response->json('data.present_today'));
        $this->assertSame(1, $response->json('data.absent_today'));
        $this->assertSame(1, $response->json('data.late_today'));
        $this->assertSame(3, $response->json('data.attendance_marked_today'));
    }

    public function test_dashboard_reports_client_company_active_employees_separately_from_office_staff(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $clientLocation = $this->makeClientLocation();

        $officeEmployee = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$officeEmployee->id}/work-assignments", [
            'work_location_id' => $office->id, 'start_date' => now()->toDateString(),
            'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertStatus(201);

        $clientEmployee = $this->makeEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$clientEmployee->id}/work-assignments", [
            'work_location_id' => $clientLocation->id, 'start_date' => now()->toDateString(),
            'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertStatus(201);

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk();

        $this->assertSame(1, $response->json('data.office_staff_count'));
        $this->assertSame(1, $response->json('data.client_company_active_employees'));
    }

    public function test_dashboard_reports_this_months_financial_totals(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/payments", [
            'payment_date' => now()->toDateString(), 'amount' => 100, 'currency' => 'USD',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => now()->toDateString(), 'reason' => 'Test', 'deduction_amount' => 10,
            'currency' => 'USD', 'payroll_period' => now()->format('Y-m'),
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => now()->toDateString(), 'amount' => 20, 'currency' => 'USD',
            'payroll_period' => now()->format('Y-m'), 'reason' => 'Test',
        ])->assertStatus(201);

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk();

        $this->assertSame(100.0, (float) $response->json('data.month_payments_total'));
        $this->assertSame(10.0, (float) $response->json('data.month_penalties_total'));
        $this->assertSame(20.0, (float) $response->json('data.month_advances_total'));
    }

    public function test_dashboard_still_returns_zeros_with_no_data(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk();

        $this->assertSame(0, $response->json('data.present_today'));
        $this->assertSame(0, $response->json('data.absent_today'));
        $this->assertSame(0, $response->json('data.late_today'));
        $this->assertSame(0, $response->json('data.fulfilled_workforce_requests'));
        $this->assertSame(0, $response->json('data.client_company_active_employees'));
        $this->assertSame('0', $response->json('data.month_payments_total'));
    }
}
