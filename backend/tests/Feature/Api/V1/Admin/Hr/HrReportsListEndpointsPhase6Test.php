<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrReportsListEndpointsPhase6Test extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'applicant',
            'application_date' => now()->toDateString(),
            ...$overrides,
        ]);
    }

    private function makeWorkLocation(): WorkLocation
    {
        $company = ClientCompany::query()->create(['name' => fake()->unique()->company(), 'status' => 'active']);

        return WorkLocation::query()->create([
            'client_company_id' => $company->id,
            'location_type' => 'client',
            'name' => 'Site '.fake()->unique()->numerify('###'),
            'status' => 'active',
        ]);
    }

    private function assertPaginationMeta($response): void
    {
        $response->assertJsonStructure(['meta' => ['current_page', 'per_page', 'total', 'last_page']]);
    }

    // --- Waiting Roster ---

    public function test_waiting_roster_lists_only_waiting_employees_sorted_oldest_first_by_default(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $notWaiting = $this->makeEmployee(['status' => 'applicant']);
        $older = $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()->subDays(10)]);
        $newer = $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()->subDay()]);
        $unavailable = $this->makeEmployee(['status' => 'waiting', 'waiting_since' => null]);

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/waiting-roster')->assertOk();
        $this->assertPaginationMeta($response);

        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains($notWaiting->id, $ids);
        $this->assertSame([$older->id, $newer->id, $unavailable->id], $ids);
    }

    public function test_waiting_roster_order_by_newest_reverses_dated_entries(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $older = $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()->subDays(10)]);
        $newer = $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()->subDay()]);

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/waiting-roster?order_by=newest')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_waiting_roster_is_rejected_without_hr_reports_view_permission(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/waiting-roster')->assertStatus(403);
    }

    // --- Workforce Request History ---

    public function test_workforce_request_history_lists_all_requests_and_filters_by_status(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $openId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id, 'quantity_needed' => 1, 'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $cancelId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id, 'quantity_needed' => 1, 'requested_date' => now()->toDateString(),
        ])->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/workforce-requests/{$cancelId}/cancel")->assertOk();

        $all = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/workforce-requests')->assertOk();
        $this->assertPaginationMeta($all);
        $this->assertCount(2, $all->json('data'));

        $cancelledOnly = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/workforce-requests?status=cancelled')->assertOk();
        $ids = array_column($cancelledOnly->json('data'), 'id');
        $this->assertSame([$cancelId], $ids);
        $this->assertNotContains($openId, $ids);
    }

    public function test_workforce_request_history_filters_by_client_company(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $locationA = $this->makeWorkLocation();
        $locationB = $this->makeWorkLocation();

        $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $locationA->id, 'quantity_needed' => 1, 'requested_date' => now()->toDateString(),
        ])->assertStatus(201);
        $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $locationB->id, 'quantity_needed' => 1, 'requested_date' => now()->toDateString(),
        ])->assertStatus(201);

        $response = $this->withToken($token)
            ->getJson("/api/v1/admin/hr/reports/workforce-requests?client_company_id={$locationA->client_company_id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    // --- Temporary Replacement History ---

    public function test_temporary_replacement_history_lists_and_filters_by_date_range(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();
        $original = $this->makeEmployee(['status' => 'active']);
        $replacement = $this->makeEmployee(['status' => 'active']);

        $assignmentId = $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$original->id}/work-assignments", [
            'work_location_id' => $location->id, 'start_date' => now()->toDateString(),
            'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertStatus(201)->json('data.id');

        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $assignmentId, 'replacement_employee_id' => $replacement->id,
            'start_date' => '2026-04-05', 'daily_rate' => 20, 'currency' => 'USD', 'reason' => 'Sick leave',
        ])->assertStatus(201);

        $inRange = $this->withToken($token)
            ->getJson('/api/v1/admin/hr/reports/temporary-replacements?from=2026-04-01&to=2026-04-30')
            ->assertOk();
        $this->assertPaginationMeta($inRange);
        $this->assertCount(1, $inRange->json('data'));

        $outOfRange = $this->withToken($token)
            ->getJson('/api/v1/admin/hr/reports/temporary-replacements?from=2026-05-01&to=2026-05-31')
            ->assertOk();
        $this->assertCount(0, $outOfRange->json('data'));
    }

    // --- Leave Report ---

    public function test_leave_report_lists_and_filters_by_leave_type_and_date_range(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee(['status' => 'active']);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", [
            'leave_type' => 'annual', 'start_date' => '2026-04-10', 'end_date' => '2026-04-12',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", [
            'leave_type' => 'sick', 'start_date' => '2026-05-01', 'end_date' => '2026-05-02',
        ])->assertStatus(201);

        $all = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/leaves')->assertOk();
        $this->assertPaginationMeta($all);
        $this->assertCount(2, $all->json('data'));

        $annualOnly = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/leaves?leave_type=annual')->assertOk();
        $this->assertCount(1, $annualOnly->json('data'));

        $aprilOnly = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/leaves?from=2026-04-01&to=2026-04-30')->assertOk();
        $this->assertCount(1, $aprilOnly->json('data'));
    }

    public function test_leave_report_is_rejected_without_hr_reports_view_permission(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/leaves')->assertStatus(403);
    }

    // --- Performance Report ---

    public function test_performance_report_lists_and_filters_by_rating(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee(['status' => 'active']);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/performance-reviews", [
            'review_date' => '2026-04-10', 'rating' => 'excellent',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/performance-reviews", [
            'review_date' => '2026-04-15', 'rating' => 'poor',
        ])->assertStatus(201);

        $all = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/performance-reviews')->assertOk();
        $this->assertPaginationMeta($all);
        $this->assertCount(2, $all->json('data'));

        $excellentOnly = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/performance-reviews?rating=excellent')->assertOk();
        $this->assertCount(1, $excellentOnly->json('data'));
    }

    // --- Financial Ledger ---

    public function test_financial_ledger_combines_payments_penalties_and_advances_tagged_by_type(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employee = $this->makeEmployee(['status' => 'active']);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/payments", [
            'payment_date' => '2026-04-05', 'amount' => 100, 'currency' => 'USD',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", [
            'penalty_date' => '2026-04-06', 'reason' => 'Late', 'deduction_amount' => 10,
            'currency' => 'USD', 'payroll_period' => '2026-04',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", [
            'advance_date' => '2026-04-07', 'amount' => 20, 'currency' => 'USD',
            'payroll_period' => '2026-04', 'reason' => 'Emergency',
        ])->assertStatus(201);

        $all = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/financial-ledger')->assertOk();
        $this->assertPaginationMeta($all);
        $types = collect($all->json('data'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['advance', 'payment', 'penalty'], $types);

        $paymentsOnly = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/financial-ledger?type=payment')->assertOk();
        $this->assertCount(1, $paymentsOnly->json('data'));
        $this->assertSame('payment', $paymentsOnly->json('data.0.type'));
    }

    public function test_financial_ledger_filters_by_employee_and_date_range(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $employeeA = $this->makeEmployee(['status' => 'active']);
        $employeeB = $this->makeEmployee(['status' => 'active']);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeA->id}/payments", [
            'payment_date' => '2026-04-05', 'amount' => 100, 'currency' => 'USD',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeB->id}/payments", [
            'payment_date' => '2026-05-05', 'amount' => 50, 'currency' => 'USD',
        ])->assertStatus(201);

        $forA = $this->withToken($token)
            ->getJson("/api/v1/admin/hr/reports/financial-ledger?employee_id={$employeeA->id}")
            ->assertOk();
        $this->assertCount(1, $forA->json('data'));
        $this->assertSame($employeeA->id, $forA->json('data.0.employee_id'));

        $aprilOnly = $this->withToken($token)
            ->getJson('/api/v1/admin/hr/reports/financial-ledger?from=2026-04-01&to=2026-04-30')
            ->assertOk();
        $this->assertCount(1, $aprilOnly->json('data'));
    }

    public function test_financial_ledger_is_rejected_without_hr_reports_view_permission(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/financial-ledger')->assertStatus(403);
    }
}
