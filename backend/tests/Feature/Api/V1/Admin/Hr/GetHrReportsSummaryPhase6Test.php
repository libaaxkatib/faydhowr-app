<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetHrReportsSummaryPhase6Test extends TestCase
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

    public function test_waiting_duration_buckets_never_fabricate_a_bucket_for_null_waiting_since(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()->subDays(2)]);
        $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()->subDays(15)]);
        $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()->subDays(45)]);
        $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()->subDays(120)]);
        $this->makeEmployee(['status' => 'waiting', 'waiting_since' => null]);

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertOk();

        $buckets = $response->json('data.waiting_duration_buckets');
        $this->assertSame(1, $buckets['under_7_days']);
        $this->assertSame(1, $buckets['seven_to_30_days']);
        $this->assertSame(1, $buckets['thirty_to_90_days']);
        $this->assertSame(1, $buckets['over_90_days']);
        $this->assertSame(1, $buckets['unavailable']);
    }

    public function test_waiting_duration_buckets_are_all_zero_when_nobody_is_waiting(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertOk();

        $this->assertSame(
            ['under_7_days' => 0, 'seven_to_30_days' => 0, 'thirty_to_90_days' => 0, 'over_90_days' => 0, 'unavailable' => 0],
            $response->json('data.waiting_duration_buckets'),
        );
    }

    public function test_workforce_requests_progress_reports_correct_unmatched_math(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'quantity_needed' => 3,
            'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $candidate = $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()]);
        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $candidate->id,
        ])->assertOk();

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertOk();

        $progress = collect($response->json('data.workforce_requests_progress'))->firstWhere('id', $requestId);

        $this->assertSame(3, $progress['quantity_needed']);
        $this->assertSame(1, $progress['matched_count']);
        $this->assertSame(2, $progress['unmatched']);
    }

    public function test_workforce_requests_progress_never_reports_negative_unmatched(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'quantity_needed' => 1,
            'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $candidate = $this->makeEmployee(['status' => 'waiting', 'waiting_since' => now()]);
        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $candidate->id,
        ])->assertOk()->assertJsonPath('data.status', 'fulfilled');

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertOk();
        $progress = collect($response->json('data.workforce_requests_progress'))->firstWhere('id', $requestId);

        $this->assertSame(0, $progress['unmatched']);
    }

    public function test_attendance_breakdown_respects_date_range_boundaries(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $inRange = $this->makeEmployee(['status' => 'active']);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$inRange->id}/work-assignments", [
            'work_location_id' => $office->id, 'start_date' => '2026-01-01',
            'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$inRange->id}/attendance", [
            'date' => '2026-04-15', 'status' => 'present',
        ])->assertOk();

        $outOfRange = $this->makeEmployee(['status' => 'active']);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$outOfRange->id}/work-assignments", [
            'work_location_id' => $office->id, 'start_date' => '2026-01-01',
            'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertStatus(201);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$outOfRange->id}/attendance", [
            'date' => '2026-05-01', 'status' => 'present',
        ])->assertOk();

        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/hr/reports/summary?from=2026-04-01&to=2026-04-30')
            ->assertOk();

        $this->assertSame(1, $response->json('data.attendance_breakdown.present'));
    }

    public function test_reports_summary_is_rejected_without_hr_reports_view_permission(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertStatus(403);
    }
}
