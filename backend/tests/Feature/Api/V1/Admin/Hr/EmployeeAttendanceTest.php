<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeAttendanceTest extends TestCase
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

    private function assignToOffice(string $token, Employee $employee): void
    {
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'work_location_id' => $office->id,
            'start_date' => now()->toDateString(),
            'salary_amount' => 500,
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
        ])->assertStatus(201);
    }

    public function test_marking_attendance_creates_a_record_and_re_marking_the_same_day_updates_it_in_place(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee();
        $this->assignToOffice($token, $employee);
        $date = now()->toDateString();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/attendance", [
            'date' => $date,
            'status' => 'present',
        ])->assertOk()->assertJsonPath('data.status', 'present');

        $this->assertDatabaseCount('employee_attendances', 1);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/attendance", [
            'date' => $date,
            'status' => 'late',
            'notes' => 'Traffic.',
        ])->assertOk()->assertJsonPath('data.status', 'late');

        $this->assertDatabaseCount('employee_attendances', 1);
        $stored = \App\Models\EmployeeAttendance::query()->where('employee_id', $employee->id)->whereDate('date', $date)->first();
        $this->assertNotNull($stored);
        $this->assertSame('late', $stored->status->value);
    }

    public function test_marking_attendance_requires_an_active_employee(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee('applicant');

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/attendance", [
            'date' => now()->toDateString(),
            'status' => 'present',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
    }

    public function test_office_only_filter_returns_only_office_assigned_employees(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $officeEmployee = $this->makeEmployee();
        $this->assignToOffice($token, $officeEmployee);
        $fieldEmployee = $this->makeEmployee();

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/employees?office_only=true')->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($officeEmployee->id, $ids);
        $this->assertNotContains($fieldEmployee->id, $ids);
    }

    public function test_attendance_roster_for_date_flags_unmarked_employees_as_null(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $marked = $this->makeEmployee();
        $this->assignToOffice($token, $marked);
        $unmarked = $this->makeEmployee();
        $this->assignToOffice($token, $unmarked);

        $date = now()->toDateString();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$marked->id}/attendance", [
            'date' => $date,
            'status' => 'absent',
        ])->assertOk();

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/attendance?date={$date}")->assertOk();
        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame('absent', $byId[$marked->id]['attendance_status']);
        $this->assertNull($byId[$unmarked->id]['attendance_status']);
    }
}
