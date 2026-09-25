<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Employee Profile Training History (visual-audit correction): the Employee
 * side of the existing TrainingBatch/TrainingBatchParticipant data was never
 * exposed on GET /hr/employees/{id}. No new table, no change to the
 * Training Batch workflow - only Employee::trainingParticipations(),
 * GetEmployeeAction's eager-load, and EmployeeResource's serialization.
 */
class EmployeeTrainingHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function actingToken(): string
    {
        $admin = Admin::factory()->superAdmin()->create();

        return $admin->createToken('t')->plainTextToken;
    }

    private function registerEmployee(string $token): array
    {
        $category = EmployeeCategory::query()->firstOrFail();

        return $this->withToken($token)->postJson('/api/v1/admin/hr/employees', [
            'full_name' => 'Training History Test',
            'phone' => fake()->unique()->e164PhoneNumber(),
            'location' => 'Hodan, Mogadishu',
            'gender' => 'female',
            'employee_category_id' => $category->id,
            'application_date' => now()->toDateString(),
        ])->json('data');
    }

    /** Walks a fresh applicant through Guarantor -> Contract -> Uniform to reach need_training. */
    private function employeeAtNeedTraining(string $token): array
    {
        $employee = $this->registerEmployee($token);
        $employeeId = $employee['id'];

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/guarantor", [
            'guarantor_name' => 'Ahmed Guarantor',
            'guarantor_phone' => '+252611110000',
            'relationship' => 'Uncle',
        ])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/guarantor/verify")->assertOk();

        $contract = $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/contracts", [])->json('data');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/contracts/{$contract['id']}/sign", [
            'signed_date' => now()->toDateString(),
        ])->assertOk();

        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$employeeId}/uniform", ['status' => 'received'])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/uniform/confirm")->assertOk();

        return $employee;
    }

    public function test_employee_profile_shows_training_history_after_a_completed_batch(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtNeedTraining($token);
        $employeeId = $employee['id'];

        $trainer = Admin::factory()->superAdmin()->create(['full_name' => 'Trainer Admin']);

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', [
            'batch_date' => '2026-04-10',
            'start_time' => '09:00',
            'end_time' => '12:00',
            'team_or_group' => 'Team A',
            'trainer_admin_id' => $trainer->id,
            'location' => 'Head Office',
        ])->assertStatus(201)->json('data');

        $this->withToken($token)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", [
            'employee_id' => $employeeId,
        ])->assertOk();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/training-batches/{$batch['id']}/complete", [])->assertOk();

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employeeId}")->assertOk();

        $response->assertJsonCount(1, 'data.training_history');
        $response->assertJsonPath('data.training_history.0.training_batch_id', $batch['id']);
        $response->assertJsonPath('data.training_history.0.batch_number', $batch['batch_number']);
        $response->assertJsonPath('data.training_history.0.batch_date', '2026-04-10');
        $response->assertJsonPath('data.training_history.0.start_time', '09:00');
        $response->assertJsonPath('data.training_history.0.end_time', '12:00');
        $response->assertJsonPath('data.training_history.0.team_or_group', 'Team A');
        $response->assertJsonPath('data.training_history.0.trainer_name', 'Trainer Admin');
        $response->assertJsonPath('data.training_history.0.location', 'Head Office');
        $response->assertJsonPath('data.training_history.0.result', 'completed');
    }

    public function test_employee_profile_shows_empty_training_history_when_never_trained(): void
    {
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token);

        $response = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee['id']}")->assertOk();

        $response->assertJsonCount(0, 'data.training_history');
    }

    public function test_absent_participant_shows_absent_result_in_training_history(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtNeedTraining($token);
        $employeeId = $employee['id'];

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', [
            'batch_date' => now()->toDateString(),
        ])->json('data');

        $this->withToken($token)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", [
            'employee_id' => $employeeId,
        ])->assertOk();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/training-batches/{$batch['id']}/complete", [
            'absent_employee_ids' => [$employeeId],
        ])->assertOk();

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employeeId}")
            ->assertOk()
            ->assertJsonPath('data.training_history.0.result', 'absent');
    }

    public function test_existing_training_batches_list_still_works_unchanged_and_has_no_duplicate_participants(): void
    {
        // AddTrainingBatchParticipantAction only allows an employee currently at the
        // Need Training pipeline stage, and completing a batch advances them past it -
        // so one employee can only ever appear in exactly one completed batch. This
        // test confirms the pre-existing Training Batch list endpoint (unmodified by
        // this change) still reflects that single participation correctly.
        $token = $this->actingToken();
        $employeeA = $this->employeeAtNeedTraining($token);
        $employeeB = $this->employeeAtNeedTraining($token);

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => '2026-03-20'])->json('data');
        $this->withToken($token)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $employeeA['id']])->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $employeeB['id']])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/admin/hr/training-batches/{$batch['id']}/complete", [])->assertOk();

        $listResponse = $this->withToken($token)->getJson('/api/v1/admin/hr/training-batches')->assertOk();
        $batchFromList = collect($listResponse->json('data'))->firstWhere('id', $batch['id']);
        $this->assertCount(2, $batchFromList['participants']);

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employeeA['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'data.training_history')
            ->assertJsonPath('data.training_history.0.training_batch_id', $batch['id']);

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employeeB['id']}")
            ->assertOk()
            ->assertJsonCount(1, 'data.training_history')
            ->assertJsonPath('data.training_history.0.training_batch_id', $batch['id']);
    }
}
