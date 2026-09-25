<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocumentCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Focused regression coverage for the HRM Phase 1 pipeline foundation
 * (docs/HRM_MARKETING_SRS.md HR §4-§12): registration reconciliation,
 * Guarantor, Contract (hard-gates Uniform), Uniform (hard-gates Training),
 * Training batches, Practical batches + the three canonical decisions.
 */
class EmployeePipelinePhase1Test extends TestCase
{
    use RefreshDatabase;

    private function actingToken(): string
    {
        $admin = Admin::factory()->superAdmin()->create();

        return $admin->createToken('t')->plainTextToken;
    }

    private function registerEmployee(string $token, array $overrides = []): array
    {
        $category = EmployeeCategory::query()->firstOrFail();

        return $this->withToken($token)->postJson('/api/v1/admin/hr/employees', array_merge([
            'full_name' => 'Test Employee',
            'phone' => fake()->unique()->e164PhoneNumber(),
            'location' => 'Hodan, Mogadishu',
            'gender' => 'female',
            'employee_category_id' => $category->id,
            'application_date' => now()->toDateString(),
        ], $overrides))->json('data');
    }

    /** Registers an employee and verifies their Guarantor only, reaching contract_pending. Does NOT touch Contract. */
    private function employeeAtContractPending(string $token): array
    {
        $employee = $this->registerEmployee($token);
        $employeeId = $employee['id'];

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/guarantor", [
            'guarantor_name' => 'Ahmed Guarantor',
            'guarantor_phone' => '+252611110000',
        ]);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/guarantor/verify");

        return $employee;
    }

    /** Walks an employee through Guarantor + signed Contract, reaching uniform_pending. Does NOT touch Uniform. */
    private function employeeAtUniformPending(string $token): array
    {
        $employee = $this->employeeAtContractPending($token);
        $employeeId = $employee['id'];

        $contract = $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/contracts", [])->json('data');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/contracts/{$contract['id']}/sign", [
            'signed_date' => now()->toDateString(),
        ]);

        return $employee;
    }

    /** Registers an employee and walks them through Guarantor -> Contract -> Uniform to reach need_training. */
    private function employeeAtNeedTraining(string $token): array
    {
        $employee = $this->employeeAtUniformPending($token);
        $employeeId = $employee['id'];

        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$employeeId}/uniform", ['status' => 'received']);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/uniform/confirm");

        return $employee;
    }

    /** Walks an employee all the way through to need_practical via a completed training batch. */
    private function employeeAtNeedPractical(string $token): array
    {
        $employee = $this->employeeAtNeedTraining($token);

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => now()->toDateString()])->json('data');
        $this->withToken($token)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $employee['id']]);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/training-batches/{$batch['id']}/complete", []);

        return $employee;
    }

    public function test_registration_requires_gender_and_location(): void
    {
        $token = $this->actingToken();
        $category = EmployeeCategory::query()->firstOrFail();

        $this->withToken($token)->postJson('/api/v1/admin/hr/employees', [
            'full_name' => 'No Gender No Location',
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => $category->id,
            'application_date' => now()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gender', 'location']);
    }

    public function test_registration_sets_damiin_needed_pipeline_stage(): void
    {
        $token = $this->actingToken();

        $employee = $this->registerEmployee($token);

        $this->assertSame('damiin_needed', $employee['pipeline_stage']);
        $this->assertSame('applicant', $employee['status']);
        $this->assertDatabaseHas('employees', ['id' => $employee['id'], 'pipeline_stage' => 'damiin_needed', 'gender' => 'female']);
    }

    public function test_guarantor_create_and_verify_advances_pipeline_to_contract_pending(): void
    {
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token);

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee['id']}/guarantor", [
            'guarantor_name' => 'Ahmed Guarantor',
            'guarantor_phone' => '+252611110000',
            'relationship' => 'Uncle',
        ])->assertOk()->assertJsonPath('data.guarantor_name', 'Ahmed Guarantor');

        $response = $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee['id']}/guarantor/verify");

        $response->assertOk()
            ->assertJsonPath('data.pipeline_stage', 'contract_pending');

        $this->assertDatabaseHas('employees', ['id' => $employee['id'], 'pipeline_stage' => 'contract_pending']);
        $this->assertNotNull($response->json('data.guarantor_confirmed_at'));
    }

    public function test_uniform_cannot_be_confirmed_before_contract_is_signed(): void
    {
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token);

        // Employee is still at damiin_needed (guarantor not even verified yet) -
        // confirming uniform must be rejected, not silently accepted.
        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employee['id']}/uniform", ['status' => 'received'])
            ->assertOk();

        $this->withToken($token)
            ->patchJson("/api/v1/admin/hr/employees/{$employee['id']}/uniform/confirm")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EMPLOYEE_CONTRACT_NOT_SIGNED');
    }

    public function test_full_guarantor_contract_uniform_sequence_advances_pipeline_to_need_training(): void
    {
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token);
        $employeeId = $employee['id'];

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/guarantor", [
            'guarantor_name' => 'Ahmed Guarantor',
            'guarantor_phone' => '+252611110000',
        ])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/guarantor/verify")
            ->assertOk()->assertJsonPath('data.pipeline_stage', 'contract_pending');

        $contract = $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/contracts", [])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'issued')
            ->json('data');

        $this->withToken($token)
            ->patchJson("/api/v1/admin/hr/employees/{$employeeId}/contracts/{$contract['id']}/sign", [
                'signed_date' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.pipeline_stage', 'uniform_pending')
            ->assertJsonPath('data.current_contract.status', 'signed');

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employeeId}/uniform", ['status' => 'received'])
            ->assertOk();

        $response = $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employeeId}/uniform/confirm");

        $response->assertOk()->assertJsonPath('data.pipeline_stage', 'need_training');

        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'pipeline_stage' => 'need_training']);
        $this->assertDatabaseHas('employee_contracts', ['employee_id' => $employeeId, 'status' => 'signed']);
        $this->assertDatabaseHas('employee_uniforms', ['employee_id' => $employeeId, 'status' => 'confirmed']);
    }

    public function test_uploading_into_an_occupied_category_supersedes_instead_of_deleting(): void
    {
        Storage::fake('local');
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token);
        $category = EmployeeDocumentCategory::query()->where('name', 'Identity Documents')->firstOrFail();

        $first = $this->withToken($token)->post("/api/v1/admin/hr/employees/{$employee['id']}/documents", [
            'file' => UploadedFile::fake()->create('id-front.pdf', 100, 'application/pdf'),
            'employee_document_category_id' => $category->id,
        ])->assertStatus(201)->json('data');

        $this->assertTrue($first['is_current']);

        $second = $this->withToken($token)->post("/api/v1/admin/hr/employees/{$employee['id']}/documents", [
            'file' => UploadedFile::fake()->create('id-front-v2.pdf', 100, 'application/pdf'),
            'employee_document_category_id' => $category->id,
        ])->assertStatus(201)->json('data');

        $this->assertDatabaseHas('employee_documents', ['id' => $first['id'], 'is_current' => false, 'superseded_by_document_id' => $second['id']]);
        $this->assertDatabaseHas('employee_documents', ['id' => $second['id'], 'is_current' => true]);
        $this->assertDatabaseCount('employee_documents', 2);
    }

    public function test_document_can_be_verified(): void
    {
        Storage::fake('local');
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token);

        $document = $this->withToken($token)->post("/api/v1/admin/hr/employees/{$employee['id']}/documents", [
            'file' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
        ])->json('data');

        $this->assertSame('pending', $document['verification_status']);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/hr/employees/{$employee['id']}/documents/{$document['id']}/verify")
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_profile_picture_upload_sets_employee_reference_and_supersedes_on_replace(): void
    {
        Storage::fake('local');
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token);

        $response = $this->withToken($token)->post("/api/v1/admin/hr/employees/{$employee['id']}/profile-picture", [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertOk();
        $firstDocumentId = $response->json('data.profile_picture_document_id');
        $this->assertNotNull($firstDocumentId);

        $second = $this->withToken($token)->post("/api/v1/admin/hr/employees/{$employee['id']}/profile-picture", [
            'file' => UploadedFile::fake()->image('photo2.jpg'),
        ])->assertOk();

        $this->assertNotSame($firstDocumentId, $second->json('data.profile_picture_document_id'));
        $this->assertDatabaseHas('employee_documents', ['id' => $firstDocumentId, 'is_current' => false]);
    }

    public function test_document_categories_are_seeded_and_hr_can_add_more(): void
    {
        $token = $this->actingToken();

        $list = $this->withToken($token)->getJson('/api/v1/admin/hr/employee-document-categories')->assertOk()->json('data');
        $this->assertCount(8, $list);
        $this->assertContains('Signed Contract', array_column($list, 'name'));

        $this->withToken($token)
            ->postJson('/api/v1/admin/hr/employee-document-categories', ['name' => 'Health Certificate'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Health Certificate');
    }

    public function test_adding_an_ineligible_employee_to_a_training_batch_is_rejected(): void
    {
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token); // still at damiin_needed

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', [
            'batch_date' => now()->toDateString(),
        ])->assertStatus(201)->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $employee['id']])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EMPLOYEE_NOT_ELIGIBLE_FOR_TRAINING');
    }

    public function test_training_batch_add_complete_advances_participants_to_need_practical_except_absentees(): void
    {
        $token = $this->actingToken();
        $attendee = $this->employeeAtNeedTraining($token);
        $absentee = $this->employeeAtNeedTraining($token);

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', [
            'batch_date' => now()->toDateString(),
            'location' => 'Head Office Training Room',
        ])->assertStatus(201)->json('data');

        $this->withToken($token)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $attendee['id']])->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $absentee['id']])->assertOk();

        $completed = $this->withToken($token)
            ->patchJson("/api/v1/admin/hr/training-batches/{$batch['id']}/complete", ['absent_employee_ids' => [$absentee['id']]])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->json('data');

        $this->assertDatabaseHas('employees', ['id' => $attendee['id'], 'pipeline_stage' => 'need_practical']);
        $this->assertDatabaseHas('employees', ['id' => $absentee['id'], 'pipeline_stage' => 'need_training']);
        $this->assertDatabaseHas('training_batch_participants', ['training_batch_id' => $batch['id'], 'employee_id' => $attendee['id'], 'result' => 'completed']);
        $this->assertDatabaseHas('training_batch_participants', ['training_batch_id' => $batch['id'], 'employee_id' => $absentee['id'], 'result' => 'absent']);
        $this->assertCount(2, $completed['participants']);
    }

    public function test_training_batch_participant_can_be_removed(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtNeedTraining($token);

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => now()->toDateString()])->json('data');
        $this->withToken($token)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $employee['id']])->assertOk();

        $participantId = \App\Models\TrainingBatchParticipant::query()->where('training_batch_id', $batch['id'])->firstOrFail()->id;

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants/{$participantId}")
            ->assertOk()
            ->assertJsonCount(0, 'data.participants');
    }

    public function test_practical_decision_rejected_for_an_employee_not_at_need_practical(): void
    {
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token); // still at damiin_needed

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee['id']}/practical-assessments", [
            'assessment_date' => now()->toDateString(),
            'result' => 'approved',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ELIGIBLE_FOR_PRACTICAL');
    }

    public function test_ku_celis_practical_creates_a_new_attempt_and_preserves_the_previous_one(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtNeedPractical($token);
        $employeeId = $employee['id'];

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/practical-batches', ['batch_date' => now()->toDateString()])->json('data');

        // Attempt #1: Ku Celis Practical - NOT a rejection, sends back to Need Practical (tracked as practical_repeat).
        $afterFirst = $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/practical-assessments", [
            'assessment_date' => now()->toDateString(),
            'result' => 'ku_celis_practical',
            'practical_batch_id' => $batch['id'],
        ])->assertStatus(201)->json('data');

        $this->assertSame('practical_repeat', $afterFirst['pipeline_stage']);
        $this->assertSame('applicant', $afterFirst['status']);
        $this->assertCount(1, $afterFirst['practical_assessments']);
        $this->assertSame(1, $afterFirst['practical_assessments'][0]['attempt_number']);

        // Attempt #2: Approved -> straight to Waiting, pipeline_stage clears, attempt #1 stays in history.
        $afterSecond = $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/practical-assessments", [
            'assessment_date' => now()->toDateString(),
            'result' => 'approved',
        ])->assertStatus(201)->json('data');

        $this->assertNull($afterSecond['pipeline_stage']);
        $this->assertSame('waiting', $afterSecond['status']);
        $this->assertNotNull($afterSecond['waiting_since']);
        $this->assertCount(2, $afterSecond['practical_assessments']);

        $this->assertDatabaseHas('employee_practical_assessments', ['employee_id' => $employeeId, 'attempt_number' => 1, 'result' => 'ku_celis_practical']);
        $this->assertDatabaseHas('employee_practical_assessments', ['employee_id' => $employeeId, 'attempt_number' => 2, 'result' => 'approved']);
        $this->assertDatabaseCount('employee_practical_assessments', 2);
        $this->assertDatabaseHas('employees', ['id' => $employeeId, 'status' => 'waiting', 'pipeline_stage' => null]);
    }

    public function test_practical_decision_rejected_moves_employee_to_rejected_pipeline_stage(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtNeedPractical($token);

        $response = $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee['id']}/practical-assessments", [
            'assessment_date' => now()->toDateString(),
            'result' => 'rejected',
        ]);

        $response->assertStatus(201)->assertJsonPath('data.pipeline_stage', 'rejected');
        $this->assertDatabaseHas('employees', ['id' => $employee['id'], 'status' => 'applicant', 'pipeline_stage' => 'rejected']);
    }

    public function test_practical_batches_can_be_created_and_updated(): void
    {
        $token = $this->actingToken();

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/practical-batches', [
            'batch_date' => now()->toDateString(),
            'location' => 'Site A',
        ])->assertStatus(201)->json('data');

        $this->assertSame('scheduled', $batch['status']);

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/practical-batches/{$batch['id']}", ['location' => 'Site B'])
            ->assertOk()
            ->assertJsonPath('data.location', 'Site B');
    }

    public function test_dashboard_reports_and_employee_list_expose_real_pipeline_stage_queues(): void
    {
        $token = $this->actingToken();
        $this->registerEmployee($token); // damiin_needed
        $this->employeeAtNeedTraining($token); // need_training

        $dashboard = $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $dashboard['damiin_needed']);
        $this->assertSame(1, $dashboard['need_training']);

        $reports = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertOk()->json('data');
        $this->assertSame(1, $reports['pipeline_breakdown']['damiin_needed']);

        $list = $this->withToken($token)
            ->getJson('/api/v1/admin/hr/employees?pipeline_stage=need_training')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('need_training', $list[0]['pipeline_stage']);
    }

    /**
     * Audit regression coverage requested after the "Applicant appears in Need
     * Training" review: proves every gate transition only fires exactly once
     * its precondition is met, and never before. Root cause of the reported
     * observation was a UI display gap (pipeline_stage wasn't shown alongside
     * the coarse status badge), not a gate-bypass bug — these tests lock in
     * that the backend gating itself is correct.
     */
    public function test_damiin_incomplete_leaves_employee_at_damiin_needed(): void
    {
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token);

        // No guarantor action taken at all.
        $fetched = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee['id']}")->assertOk()->json('data');
        $this->assertSame('damiin_needed', $fetched['pipeline_stage']);

        $list = $this->withToken($token)->getJson('/api/v1/admin/hr/employees?pipeline_stage=need_training')->assertOk()->json('data');
        $this->assertEmpty(array_filter($list, fn ($e) => $e['id'] === $employee['id']));
    }

    public function test_damiin_verified_with_unsigned_contract_stays_at_contract_pending(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtContractPending($token);
        $employeeId = $employee['id'];

        // Issue a contract but never sign it.
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employeeId}/contracts", [])->assertStatus(201);

        $fetched = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employeeId}")->assertOk()->json('data');
        $this->assertSame('contract_pending', $fetched['pipeline_stage']);
        $this->assertSame('issued', $fetched['current_contract']['status']);
    }

    public function test_uniform_not_confirmed_means_not_need_training(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtUniformPending($token);
        $employeeId = $employee['id'];

        // Uniform record exists (purchased/received) but never confirmed.
        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$employeeId}/uniform", ['status' => 'received'])->assertOk();

        $fetched = $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employeeId}")->assertOk()->json('data');
        $this->assertSame('uniform_pending', $fetched['pipeline_stage']);

        $needTrainingList = $this->withToken($token)->getJson('/api/v1/admin/hr/employees?pipeline_stage=need_training')->assertOk()->json('data');
        $this->assertEmpty(array_filter($needTrainingList, fn ($e) => $e['id'] === $employeeId));
    }

    /**
     * The exact regression for the reported observation: a freshly-registered
     * Applicant (status=applicant, the same status every pre-Waiting employee
     * holds by design) must never appear in the Need Training queue just
     * because that status string exists — only real gate completion does it.
     */
    public function test_plain_applicant_never_appears_in_need_training_queue(): void
    {
        $token = $this->actingToken();
        $freshApplicant = $this->registerEmployee($token);
        $this->assertSame('applicant', $freshApplicant['status']);

        $needTrainingList = $this->withToken($token)->getJson('/api/v1/admin/hr/employees?pipeline_stage=need_training')->assertOk()->json('data');
        $this->assertEmpty(array_filter($needTrainingList, fn ($e) => $e['id'] === $freshApplicant['id']));

        $dashboard = $this->withToken($token)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');
        $this->assertSame(0, $dashboard['need_training']);
        $this->assertSame(1, $dashboard['damiin_needed']);
    }

    public function test_training_batch_rejects_employee_who_has_not_completed_damiin(): void
    {
        $token = $this->actingToken();
        $employee = $this->registerEmployee($token); // damiin_needed only

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => now()->toDateString()])->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $employee['id']])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EMPLOYEE_NOT_ELIGIBLE_FOR_TRAINING');
    }

    public function test_training_batch_rejects_employee_who_has_not_signed_contract(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtContractPending($token); // damiin verified, no contract yet

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => now()->toDateString()])->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $employee['id']])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EMPLOYEE_NOT_ELIGIBLE_FOR_TRAINING');
    }

    public function test_training_batch_rejects_employee_who_has_not_confirmed_uniform(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtUniformPending($token); // contract signed, uniform not confirmed

        $batch = $this->withToken($token)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => now()->toDateString()])->json('data');

        $this->withToken($token)
            ->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $employee['id']])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'EMPLOYEE_NOT_ELIGIBLE_FOR_TRAINING');
    }

    public function test_practical_decision_rejects_employee_who_has_not_completed_training(): void
    {
        $token = $this->actingToken();
        $employee = $this->employeeAtNeedTraining($token); // uniform confirmed, training not done

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee['id']}/practical-assessments", [
            'assessment_date' => now()->toDateString(),
            'result' => 'approved',
        ])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ELIGIBLE_FOR_PRACTICAL');
    }
}
