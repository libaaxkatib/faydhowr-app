<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkforceRequestTest extends TestCase
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

    private function makeWaitingEmployee(array $overrides = []): Employee
    {
        return $this->makeEmployee([
            'status' => 'waiting',
            'waiting_since' => now(),
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

    public function test_create_list_update_and_cancel_a_workforce_request(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $created = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'gender_requirement' => 'female',
            'quantity_needed' => 2,
            'requested_date' => now()->toDateString(),
        ])->assertStatus(201)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.matched_count', 0)
            ->json('data.id');

        $this->withToken($token)->getJson('/api/v1/admin/hr/workforce-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($token)->putJson("/api/v1/admin/hr/workforce-requests/{$created}", [
            'quantity_needed' => 3,
            'notes' => 'Updated note',
        ])->assertOk()
            ->assertJsonPath('data.quantity_needed', 3)
            ->assertJsonPath('data.notes', 'Updated note');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/workforce-requests/{$created}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_candidates_endpoint_only_returns_waiting_employees_sorted_oldest_first_with_match_flags(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'gender_requirement' => 'female',
            'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $notWaiting = $this->makeEmployee(['status' => 'applicant']);
        $olderMatch = $this->makeWaitingEmployee(['gender' => 'female', 'waiting_since' => now()->subDays(5)]);
        $newerMismatch = $this->makeWaitingEmployee(['gender' => 'male', 'waiting_since' => now()->subDay()]);

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/workforce-requests/{$requestId}/candidates")
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains($notWaiting->id, $ids);
        $this->assertSame([$olderMatch->id, $newerMismatch->id], $ids);

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($byId[$olderMatch->id]['gender_match']);
        $this->assertFalse($byId[$newerMismatch->id]['gender_match']);
    }

    public function test_confirm_creates_match_without_changing_employee_status_and_updates_request_progress(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'quantity_needed' => 2,
            'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $first = $this->makeWaitingEmployee();
        $second = $this->makeWaitingEmployee();

        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $first->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'partially_filled')
            ->assertJsonPath('data.matched_count', 1);

        $first->refresh();
        $this->assertSame('waiting', $first->status->value);
        $this->assertNull($first->pipeline_stage);
        $this->assertNotNull($first->waiting_since);

        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $second->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'fulfilled')
            ->assertJsonPath('data.matched_count', 2);
    }

    public function test_confirm_blocked_for_non_waiting_employee(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $applicant = $this->makeEmployee(['status' => 'applicant']);

        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $applicant->id,
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_WAITING');
    }

    public function test_confirm_blocked_for_fulfilled_or_cancelled_request(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'quantity_needed' => 1,
            'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $filler = $this->makeWaitingEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $filler->id,
        ])->assertOk()->assertJsonPath('data.status', 'fulfilled');

        $another = $this->makeWaitingEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $another->id,
        ])->assertStatus(422)->assertJsonPath('error_code', 'WORKFORCE_REQUEST_NOT_OPEN');

        $cancelRequestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'requested_date' => now()->toDateString(),
        ])->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/workforce-requests/{$cancelRequestId}/cancel")->assertOk();

        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$cancelRequestId}/confirm", [
            'employee_id' => $another->id,
        ])->assertStatus(422)->assertJsonPath('error_code', 'WORKFORCE_REQUEST_NOT_OPEN');
    }

    public function test_duplicate_match_is_rejected(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'quantity_needed' => 5,
            'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $employee = $this->makeWaitingEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $employee->id,
        ])->assertOk();

        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $employee->id,
        ])->assertStatus(422)->assertJsonPath('error_code', 'WORKFORCE_REQUEST_ALREADY_MATCHED');
    }

    public function test_cancelling_a_request_preserves_existing_matches(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('t')->plainTextToken;
        $location = $this->makeWorkLocation();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', [
            'work_location_id' => $location->id,
            'quantity_needed' => 5,
            'requested_date' => now()->toDateString(),
        ])->json('data.id');

        $employee = $this->makeWaitingEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", [
            'employee_id' => $employee->id,
        ])->assertOk();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/workforce-requests/{$requestId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonCount(1, 'data.matches');
    }
}
