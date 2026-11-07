<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkforceRequest;
use App\Models\WorkforceRequestMatch;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner decision B-Q4 (2026-11-06): a FULFILLED workforce request may be cancelled. Cancelling is a status change only - the request's own data, its matches,
 * the matched employees and their assignments are untouched, and the audit trail records that a fulfilled request was cancelled. Synthetic data only.
 */
class FulfilledWorkforceRequestCancelTest extends TestCase
{
    use RefreshDatabase;

    private function waitingEmployee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'waiting',
            'waiting_since' => now(),
            'application_date' => now()->toDateString(),
        ]);
    }

    private function location(): WorkLocation
    {
        $company = ClientCompany::query()->create(['name' => fake()->unique()->company(), 'status' => 'active']);

        return WorkLocation::query()->create(['client_company_id' => $company->id, 'location_type' => 'client', 'name' => 'Site '.fake()->unique()->numerify('###'), 'status' => 'active']);
    }

    public function test_a_fulfilled_request_can_be_cancelled_and_nothing_else_changes(): void
    {
        $token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
        $location = $this->location();

        $requestId = $this->withToken($token)->postJson('/api/v1/admin/hr/workforce-requests', ['work_location_id' => $location->id, 'quantity_needed' => 1, 'requested_date' => now()->toDateString()])->json('data.id');
        $employee = $this->waitingEmployee();
        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", ['employee_id' => $employee->id])->assertOk()->assertJsonPath('data.status', 'fulfilled');

        // The matched employee already holds an assignment (created independently of the match, as in real use).
        $assignment = EmployeeWorkAssignment::query()->create([
            'employee_id' => $employee->id, 'work_location_id' => $location->id, 'client_company_id' => $location->client_company_id,
            'start_date' => '2026-10-01', 'salary_amount' => 250, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'status' => 'active',
        ]);

        $requestBefore = WorkforceRequest::query()->findOrFail($requestId)->getAttributes();
        $matchesBefore = WorkforceRequestMatch::query()->where('workforce_request_id', $requestId)->get()->map->getAttributes()->all();
        $employeeBefore = $employee->fresh()->getAttributes();
        $assignmentBefore = $assignment->fresh()->getAttributes();
        $auditBefore = AuditLog::query()->count();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/workforce-requests/{$requestId}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonCount(1, 'data.matches');

        // Only the status (and its timestamp) of the request changed; every other column, the match, the employee and the assignment are as they were.
        $requestAfter = WorkforceRequest::query()->findOrFail($requestId)->getAttributes();
        $this->assertSame('fulfilled', $requestBefore['status']);
        $this->assertSame('cancelled', $requestAfter['status']);
        unset($requestBefore['status'], $requestBefore['updated_at'], $requestAfter['status'], $requestAfter['updated_at']);
        $this->assertSame($requestBefore, $requestAfter);
        $this->assertSame($matchesBefore, WorkforceRequestMatch::query()->where('workforce_request_id', $requestId)->get()->map->getAttributes()->all());
        $this->assertSame($employeeBefore, $employee->fresh()->getAttributes());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getAttributes());
        $this->assertSame('active', $assignment->fresh()->status->value);

        // One audit entry, saying what the request was before.
        $this->assertSame($auditBefore + 1, AuditLog::query()->count());
        $log = AuditLog::query()->where('entity_type', WorkforceRequest::class)->where('entity_id', $requestId)->latest('id')->firstOrFail();
        $this->assertSame('fulfilled', $log->metadata['from_status']);
        $this->assertSame(1, $log->metadata['matches_kept']);
        $this->assertStringContainsString('it was fulfilled', $log->description);

        // A cancelled request still accepts no new match.
        $this->withToken($token)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", ['employee_id' => $this->waitingEmployee()->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'WORKFORCE_REQUEST_NOT_OPEN');
    }
}
