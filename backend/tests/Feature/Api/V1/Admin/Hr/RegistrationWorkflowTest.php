<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Actions\Hr\MoveEmployeeToWaitingAction;
use App\Enums\AdminRole;
use App\Enums\EmployeeRegistrationOrigin;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeStatusHistory;
use App\Models\Permission;
use App\Models\Position;
use App\Models\WorkforceRequest;
use App\Support\Hr\EmployeeRegistrationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner rule (2026-10-20): people who were already registered ("existing") are managed one by one - an authorized user moves them to the
 * stage they need, with a reason, and it is audited - while a NEW registration must complete guarantor, contract, uniform, training and
 * practice (each with its record, in that order) before it can be Waiting, Active or a company employee. Both halves are enforced on the
 * server, so neither the screen nor a direct API request can go around them.
 */
class RegistrationWorkflowTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $super;

    private string $hrManager;

    private Position $position;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = $this->superAdminToken()[1];
        $this->hrManager = $this->tokenFor(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $this->position = Position::query()->create(['name' => 'Cleaner W']);
        $this->company = $this->makeCompany('Workflow Co');
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @param list<string> $keys */
    private function tokenFor(AdminRole $role, array $keys): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($keys as $key) {
            DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    /** A real registration through the API: a NEW person at Applicant / Damiin Needed. */
    private function register(): array
    {
        return $this->as($this->super)->postJson('/api/v1/admin/hr/employees', [
            'full_name' => 'Workflow Person',
            'phone' => fake()->unique()->e164PhoneNumber(),
            'location' => 'Hodan, Mogadishu',
            'gender' => 'female',
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'application_date' => now()->toDateString(),
        ])->assertCreated()->json('data');
    }

    private function url(int $id, string $tail = ''): string
    {
        return "/api/v1/admin/hr/employees/{$id}{$tail}";
    }

    private function guarantor(int $id): void
    {
        $this->as($this->super)->postJson($this->url($id, '/guarantor'), ['guarantor_name' => 'Ahmed G', 'guarantor_phone' => '+252611110000'])->assertOk();
        $this->as($this->super)->patchJson($this->url($id, '/guarantor/verify'))->assertOk();
    }

    private function contract(int $id): void
    {
        $contract = $this->as($this->super)->postJson($this->url($id, '/contracts'), [])->json('data');
        $this->as($this->super)->patchJson($this->url($id, "/contracts/{$contract['id']}/sign"), ['signed_date' => now()->toDateString()])->assertOk();
    }

    private function uniform(int $id): void
    {
        $this->as($this->super)->putJson($this->url($id, '/uniform'), ['status' => 'received'])->assertOk();
        $this->as($this->super)->patchJson($this->url($id, '/uniform/confirm'))->assertOk();
    }

    private function training(int $id): void
    {
        $batch = $this->as($this->super)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => now()->toDateString()])->json('data');
        $this->as($this->super)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $id])->assertSuccessful();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/training-batches/{$batch['id']}/complete", [])->assertOk();
    }

    private function practical(int $id, string $result = 'approved'): void
    {
        $this->as($this->super)->postJson($this->url($id, '/practical-assessments'), ['assessment_date' => now()->toDateString(), 'result' => $result])->assertSuccessful();
    }

    /** The full workflow, in order. Ends Waiting. */
    private function complete(int $id): void
    {
        $this->guarantor($id);
        $this->contract($id);
        $this->uniform($id);
        $this->training($id);
        $this->practical($id);
    }

    private function setStatus(string $token, int $id, string $status, array $extra = [])
    {
        return $this->as($token)->patchJson($this->url($id, '/status'), ['status' => $status, ...$extra]);
    }

    /** A person who was in the register before the workflow existed. */
    private function existing(string $status = 'applicant', array $extra = []): Employee
    {
        $e = $this->makeEmployee($status);
        $e->update(['registration_origin' => 'existing', ...$extra]);

        return $e->fresh();
    }

    /** A new registration that is (against the rules) marked with a later status, as bad data or a bypass attempt would leave it. */
    private function newPerson(string $status): Employee
    {
        $e = $this->makeEmployee($status);
        $e->update(['registration_origin' => 'new']);

        return $e->fresh();
    }

    private function assignBody(Employee $employee): array
    {
        return ['employee_id' => $employee->id, 'assignment_type' => 'permanent', 'position_id' => $this->position->id, 'start_date' => now()->toDateString(), 'salary_amount' => 200, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'];
    }

    // ---------------------------------------------------------------- origin marker

    public function test_the_migration_marks_everyone_already_registered_as_existing_and_new_registrations_default_to_new(): void
    {
        $migration = require base_path('database/migrations/2026_10_20_110000_add_registration_origin_to_employees_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('employees', 'registration_origin'));

        $category = EmployeeCategory::query()->firstOrFail()->id;

        foreach ([['EMP-OLD1', 'Old One', '611000001', 'active'], ['EMP-OLD2', 'Old Two', '611000002', 'waiting']] as [$number, $name, $phone, $status]) {
            DB::table('employees')->insert(['employee_number' => $number, 'full_name' => $name, 'phone' => $phone, 'employee_category_id' => $category, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = DB::table('employees')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $migration->up();

        $this->assertSame(['existing', 'existing'], DB::table('employees')->orderBy('id')->pluck('registration_origin')->all());
        // Additive: no other column of any person changed.
        $after = DB::table('employees')->orderBy('id')->get()->map(fn ($r) => (array) $r)->map(fn ($r) => Arr::except($r, 'registration_origin'))->all();
        $this->assertSame($before, $after);

        $registered = $this->register();
        $this->assertSame('new', $registered['registration_origin']);
        $this->assertDatabaseHas('employees', ['id' => $registered['id'], 'registration_origin' => 'new']);
    }

    public function test_office_staff_are_their_own_origin_and_are_not_held_to_the_recruitment_workflow(): void
    {
        $position = Position::query()->create(['name' => 'Office Clerk W']);
        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/office-staff', [
            'full_name' => 'Office Person', 'phone' => '0615700123', 'position_id' => $position->id, 'monthly_salary' => '150', 'start_date' => '2026-10-01',
            'emergency_contact_name' => 'Emergency Person', 'emergency_contact_phone' => '0615333444',
            'guarantor_name' => 'Guarantor Person', 'guarantor_phone' => '0615555666',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('employees', ['id' => $id, 'registration_origin' => 'office_staff', 'status' => 'active']);
        $this->assertFalse((new EmployeeRegistrationWorkflow)->blocks(Employee::query()->findOrFail($id)));
    }

    // ---------------------------------------------------------------- new registrations

    public function test_a_new_registration_shows_each_required_step_and_none_is_done(): void
    {
        $e = $this->register();
        $shown = $this->as($this->super)->getJson($this->url($e['id']))->assertOk()->json('data');

        $this->assertSame('new', $shown['registration_origin']);
        $this->assertTrue($shown['registration_workflow']['applies']);
        $this->assertFalse($shown['registration_workflow']['complete']);
        $this->assertSame(['guarantor', 'contract', 'uniform', 'training', 'practice'], array_column($shown['registration_workflow']['steps'], 'key'));
        $this->assertSame([false, false, false, false, false], array_column($shown['registration_workflow']['steps'], 'done'));
    }

    public function test_a_new_registration_cannot_reach_waiting_or_active_through_the_status_chain_and_nothing_changes(): void
    {
        $e = $this->register();
        $id = $e['id'];

        $this->setStatus($this->hrManager, $id, 'recruitment')->assertOk();
        $this->setStatus($this->hrManager, $id, 'practical')->assertOk();
        $historyBefore = EmployeeStatusHistory::query()->where('employee_id', $id)->count();

        $refused = $this->setStatus($this->hrManager, $id, 'waiting')->assertStatus(422)->assertJsonPath('error_code', 'REGISTRATION_WORKFLOW_INCOMPLETE');
        $this->assertStringContainsString('Guarantor verified', $refused->json('message'));
        $this->assertStringContainsString('Practical approved', $refused->json('message'));

        // The HR Manager cannot override, with or without a reason.
        $this->setStatus($this->hrManager, $id, 'waiting', ['override_reason' => 'I would like this person to skip it'])->assertStatus(403)->assertJsonPath('error_code', 'TRANSITION_OVERRIDE_FORBIDDEN');

        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'practical']);
        $this->assertSame($historyBefore, EmployeeStatusHistory::query()->where('employee_id', $id)->count());
    }

    public function test_a_super_admin_needs_a_written_reason_to_override_and_it_is_recorded(): void
    {
        $e = $this->register();
        $id = $e['id'];
        $this->setStatus($this->super, $id, 'recruitment')->assertOk();
        $this->setStatus($this->super, $id, 'practical')->assertOk();

        $this->setStatus($this->super, $id, 'waiting')->assertStatus(422)->assertJsonPath('error_code', 'REGISTRATION_WORKFLOW_OVERRIDE_REASON_REQUIRED');
        $this->setStatus($this->super, $id, 'waiting', ['override_reason' => 'too short'])->assertStatus(422)->assertJsonPath('error_code', 'REGISTRATION_WORKFLOW_OVERRIDE_REASON_REQUIRED');
        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'practical']);

        $this->setStatus($this->super, $id, 'waiting', ['override_reason' => 'Trained abroad; the owner approved skipping the local steps'])->assertOk()->assertJsonPath('data.status', 'waiting');

        $history = EmployeeStatusHistory::query()->where('employee_id', $id)->where('to_status', 'waiting')->firstOrFail();
        $this->assertSame('override', $history->source);
        $this->assertSame('Trained abroad; the owner approved skipping the local steps', $history->override_reason);
        $audit = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $id)->get()->first(fn ($l) => ($l->metadata['registration_workflow_override'] ?? false) === true);
        $this->assertNotNull($audit);
        $this->assertContains('Guarantor verified', $audit->metadata['registration_steps_missing']);

        // The recorded override is what makes the person eligible afterwards: the HR Manager can now activate and assign.
        $this->setStatus($this->hrManager, $id, 'active')->assertOk();
        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees", $this->assignBody(Employee::query()->findOrFail($id)))->assertCreated();
    }

    public function test_the_steps_are_enforced_in_order_by_the_records_each_stage_needs(): void
    {
        $id = $this->register()['id'];

        // Nothing later than the guarantor can be recorded first.
        $this->as($this->super)->patchJson($this->url($id, '/uniform/confirm'))->assertStatus(404); // there is no uniform record yet
        $this->as($this->super)->postJson($this->url($id, '/practical-assessments'), ['assessment_date' => now()->toDateString(), 'result' => 'approved'])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ELIGIBLE_FOR_PRACTICAL');

        $this->guarantor($id);
        $batch = $this->as($this->super)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => now()->toDateString()])->json('data');
        $this->as($this->super)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $id])->assertStatus(422);
        $this->assertDatabaseHas('employees', ['id' => $id, 'pipeline_stage' => 'contract_pending']);

        $this->contract($id);
        $this->assertDatabaseHas('employees', ['id' => $id, 'pipeline_stage' => 'uniform_pending']);
        $this->as($this->super)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $id])->assertStatus(422);

        $this->uniform($id);
        $this->assertDatabaseHas('employees', ['id' => $id, 'pipeline_stage' => 'need_training']);
        $this->as($this->super)->postJson($this->url($id, '/practical-assessments'), ['assessment_date' => now()->toDateString(), 'result' => 'approved'])->assertStatus(422);

        $this->as($this->super)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $id])->assertSuccessful();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/training-batches/{$batch['id']}/complete", [])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $id, 'pipeline_stage' => 'need_practical']);

        // The status chain is still shut at every point before the practice is approved.
        $this->setStatus($this->hrManager, $id, 'waiting')->assertStatus(422);
        $this->practical($id);
        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'waiting', 'registration_origin' => 'new']);
    }

    public function test_a_new_registration_that_completed_every_step_becomes_waiting_active_and_a_company_employee_without_any_override(): void
    {
        $id = $this->register()['id'];
        $this->complete($id);

        $shown = $this->as($this->super)->getJson($this->url($id))->json('data.registration_workflow');
        $this->assertTrue($shown['complete']);
        $this->assertSame([], $shown['missing']);

        $this->setStatus($this->hrManager, $id, 'active')->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $id)->where('source', 'override')->count());

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees", $this->assignBody(Employee::query()->findOrFail($id)))->assertCreated();
    }

    public function test_a_practical_decision_other_than_approved_does_not_complete_the_workflow(): void
    {
        $id = $this->register()['id'];
        $this->guarantor($id);
        $this->contract($id);
        $this->uniform($id);
        $this->training($id);
        $this->practical($id, 'ku_celis_practical');

        $this->assertFalse((new EmployeeRegistrationWorkflow)->isComplete(Employee::query()->findOrFail($id)));
        $this->setStatus($this->hrManager, $id, 'waiting')->assertStatus(422);
        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'applicant', 'pipeline_stage' => 'practical_repeat']);
    }

    public function test_a_new_registration_cannot_be_added_as_a_company_employee_by_any_route(): void
    {
        $e = $this->newPerson('active');
        $this->assertFalse($e->registration_origin === EmployeeRegistrationOrigin::Existing);

        // Company screen.
        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees", $this->assignBody($e))
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_REGISTRATION_INCOMPLETE');

        // Direct work-assignment API.
        $this->as($this->super)->postJson($this->url($e->id, '/work-assignments'), ['client_company_id' => $this->company->id, 'start_date' => now()->toDateString(), 'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_REGISTRATION_INCOMPLETE');

        // The picker says why, instead of offering the person.
        $candidate = collect($this->as($this->hrManager)->getJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employee-candidates?q={$e->employee_number}")->json('data'))->firstWhere('id', $e->id);
        $this->assertFalse($candidate['eligible']);
        $this->assertStringContainsString('registration workflow', $candidate['ineligible_reason']);

        // Covering a company position as a temporary replacement.
        $holder = $this->existing('active');
        $assignment = $this->makeAssignment($holder, $this->company);
        $cover = $this->newPerson('active');
        $cover->update(['employment_type' => 'temporary']);
        $this->as($this->super)->postJson('/api/v1/admin/hr/temporary-replacements', ['work_assignment_id' => $assignment->id, 'replacement_employee_id' => $cover->id, 'start_date' => now()->toDateString()])
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_REGISTRATION_INCOMPLETE');
        $this->assertDatabaseCount('temporary_replacements', 0);
        // The refusals above created no assignment for the new person (only the holder's own exists).
        $this->assertDatabaseMissing('employee_work_assignments', ['employee_id' => $e->id]);

        // And the same person, once they have the records, is accepted by the same routes.
        $done = $this->register();
        $this->complete($done['id']);
        $this->setStatus($this->super, $done['id'], 'active')->assertOk();
        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees", $this->assignBody(Employee::query()->findOrFail($done['id'])))->assertCreated();
    }

    public function test_a_new_registration_cannot_be_matched_to_a_client_request_or_rehired_without_the_workflow(): void
    {
        $waiting = $this->newPerson('waiting');
        $location = $this->makeClientLocation($this->company);
        $request = $this->as($this->super)->postJson('/api/v1/admin/hr/workforce-requests', ['work_location_id' => $location->id, 'gender_requirement' => 'female', 'requested_date' => now()->toDateString()]);
        $requestId = $request->json('data.id');
        $this->assertNotNull($requestId, $request->getContent());

        $this->as($this->super)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", ['employee_id' => $waiting->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'REGISTRATION_WORKFLOW_INCOMPLETE');
        $this->assertSame(0, WorkforceRequest::query()->findOrFail($requestId)->matches()->count());

        $separated = $this->newPerson('inactive');
        $this->as($this->super)->patchJson($this->url($separated->id, '/rehire'), [])->assertStatus(422)->assertJsonPath('error_code', 'REGISTRATION_WORKFLOW_INCOMPLETE');
        $this->assertDatabaseHas('employees', ['id' => $separated->id, 'status' => 'inactive']);

        // Existing records are not held to it.
        $oldWaiting = $this->existing('waiting');
        $this->as($this->super)->postJson("/api/v1/admin/hr/workforce-requests/{$requestId}/confirm", ['employee_id' => $oldWaiting->id])->assertSuccessful();
    }

    public function test_bulk_activation_does_not_bypass_the_workflow(): void
    {
        $blocked = $this->newPerson('waiting');
        $old = $this->existing('waiting');
        $records = [['employee_id' => $blocked->id], ['employee_id' => $old->id]];

        // Dry run tells the truth about each person.
        $dry = $this->as($this->hrManager)->postJson('/api/v1/admin/hr/employees/bulk-activate', ['records' => $records, 'note' => 'Batch activation test', 'dry_run' => true])->assertOk()->json('data.results');
        $this->assertSame('requires_override', $dry[0]['outcome']);
        $this->assertSame('REGISTRATION_WORKFLOW_INCOMPLETE', $dry[0]['code']);
        $this->assertSame('would_activate', $dry[1]['outcome']);

        // A real run by the HR Manager activates only the existing person.
        $real = $this->as($this->hrManager)->postJson('/api/v1/admin/hr/employees/bulk-activate', ['records' => $records, 'note' => 'Batch activation test', 'dry_run' => false, 'confirm' => true])->assertOk()->json('data.results');
        $this->assertSame('failed', $real[0]['outcome']);
        $this->assertSame('REGISTRATION_WORKFLOW_INCOMPLETE', $real[0]['code']);
        $this->assertSame('activated', $real[1]['outcome']);
        $this->assertDatabaseHas('employees', ['id' => $blocked->id, 'status' => 'waiting']);

        // Even with a reason, an HR Manager cannot override.
        $withReason = $this->as($this->hrManager)->postJson('/api/v1/admin/hr/employees/bulk-activate', ['records' => [['employee_id' => $blocked->id]], 'note' => 'Batch activation test', 'dry_run' => false, 'confirm' => true, 'override_reason' => 'Please let this person through'])->json('data.results');
        $this->assertSame('failed', $withReason[0]['outcome']);
        $this->assertDatabaseHas('employees', ['id' => $blocked->id, 'status' => 'waiting']);

        // A Super Admin with a reason can, and it is recorded.
        $super = $this->as($this->super)->postJson('/api/v1/admin/hr/employees/bulk-activate', ['records' => [['employee_id' => $blocked->id]], 'note' => 'Batch activation test', 'dry_run' => false, 'confirm' => true, 'override_reason' => 'Owner approved this person without the steps'])->json('data.results');
        $this->assertSame('activated', $super[0]['outcome']);
        $this->assertSame('override', EmployeeStatusHistory::query()->where('employee_id', $blocked->id)->where('to_status', 'active')->value('source'));
    }

    public function test_a_waiting_list_import_cannot_move_a_new_registration_but_still_moves_an_existing_one(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $new = $this->newPerson('applicant');
        $old = $this->existing('applicant');

        $action = app(MoveEmployeeToWaitingAction::class);

        try {
            $action->handle($new, 'Waiting-list import test', $admin, 1);
            $this->fail('A new registration must not be moved to Waiting by an import.');
        } catch (HttpResponseException $e) {
            $this->assertSame('REGISTRATION_WORKFLOW_INCOMPLETE', $e->getResponse()->getData(true)['error_code']);
        }
        $this->assertDatabaseHas('employees', ['id' => $new->id, 'status' => 'applicant']);

        $action->handle($old, 'Waiting-list import test', $admin, 1);
        $this->assertDatabaseHas('employees', ['id' => $old->id, 'status' => 'waiting']);
    }

    // ---------------------------------------------------------------- existing records

    public function test_existing_people_follow_the_plain_status_chain_as_before(): void
    {
        $e = $this->existing('applicant');

        foreach (['recruitment', 'practical', 'waiting', 'active'] as $to) {
            $this->setStatus($this->hrManager, $e->id, $to)->assertOk();
        }

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees", $this->assignBody($e->fresh()))->assertCreated();
    }

    public function test_an_authorized_user_can_move_an_existing_person_to_every_available_stage_with_a_reason_and_it_is_audited(): void
    {
        $e = $this->existing('waiting', ['waiting_since' => now()->subDays(3)]);

        foreach (['damiin_needed', 'contract_pending', 'uniform_pending', 'need_training', 'need_practical', 'practical_repeat'] as $stage) {
            $this->as($this->hrManager)->postJson($this->url($e->id, '/move-stage'), ['stage' => $stage, 'reason' => "Owner reviewed the file and chose {$stage}"])
                ->assertOk()->assertJsonPath('data.pipeline_stage', $stage)->assertJsonPath('data.status', 'applicant')->assertJsonPath('data.registration_origin', 'existing');
            $this->assertNull($e->fresh()->waiting_since);
        }

        $this->as($this->super)->postJson($this->url($e->id, '/move-stage'), ['stage' => 'waiting', 'reason' => 'Owner moved this person to the waiting list'])
            ->assertOk()->assertJsonPath('data.status', 'waiting')->assertJsonPath('data.pipeline_stage', null);
        $this->assertNotNull($e->fresh()->waiting_since);

        // Each move is in the status history (with its reason) and in the audit log.
        $moves = EmployeeStatusHistory::query()->where('employee_id', $e->id)->where('source', 'stage_move')->orderBy('id')->get();
        $this->assertCount(7, $moves);
        $this->assertStringContainsString('Reason: Owner moved this person to the waiting list', $moves->last()->note);
        $this->assertStringContainsString('Practical Repeat to Waiting', $moves->last()->note);

        $logs = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $e->id)->get()->filter(fn ($l) => ($l->metadata['stage_move'] ?? false) === true);
        $this->assertCount(7, $logs);
        $last = $logs->sortBy('id')->last();
        $this->assertSame(['practical_repeat', null, 'applicant', 'waiting', 'Owner moved this person to the waiting list'], [$last->metadata['from_pipeline_stage'], $last->metadata['to_pipeline_stage'], $last->metadata['from_status'], $last->metadata['to_status'], $last->metadata['reason']]);
        $this->assertNotNull($last->admin_id);
    }

    public function test_moving_an_existing_person_preserves_every_record(): void
    {
        $e = $this->existing('applicant', ['pipeline_stage' => 'need_practical', 'notes' => 'keep these notes', 'location' => 'Hodan']);
        $this->as($this->super)->postJson($this->url($e->id, '/guarantor'), ['guarantor_name' => 'Ahmed G', 'guarantor_phone' => '+252611110000'])->assertOk();
        $this->as($this->super)->postJson($this->url($e->id, '/contracts'), [])->assertSuccessful();
        $before = $e->fresh()->only(['employee_number', 'full_name', 'phone', 'location', 'notes', 'employee_category_id', 'registration_origin']);
        $counts = fn () => [DB::table('employee_guarantors')->where('employee_id', $e->id)->count(), DB::table('employee_contracts')->where('employee_id', $e->id)->count(), DB::table('employee_status_histories')->where('employee_id', $e->id)->count()];
        $c0 = $counts();

        $this->as($this->hrManager)->postJson($this->url($e->id, '/move-stage'), ['stage' => 'need_training', 'reason' => 'Corrected: training is still outstanding'])->assertOk();

        $this->assertEquals($before, $e->fresh()->only(array_keys($before)));
        $this->assertSame([$c0[0], $c0[1], $c0[2] + 1], $counts());
        // The person stays an existing record after being moved, so the workflow still does not hold them back.
        $this->assertSame('existing', $e->fresh()->registration_origin->value);
    }

    public function test_a_moved_existing_person_can_carry_on_through_the_normal_pipeline(): void
    {
        $e = $this->existing('waiting');
        $this->as($this->hrManager)->postJson($this->url($e->id, '/move-stage'), ['stage' => 'need_training', 'reason' => 'Needs training before placement'])->assertOk();

        $batch = $this->as($this->super)->postJson('/api/v1/admin/hr/training-batches', ['batch_date' => now()->toDateString()])->json('data');
        $this->as($this->super)->postJson("/api/v1/admin/hr/training-batches/{$batch['id']}/participants", ['employee_id' => $e->id])->assertSuccessful();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/training-batches/{$batch['id']}/complete", [])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $e->id, 'pipeline_stage' => 'need_practical']);
        $this->practical($e->id);
        $this->assertDatabaseHas('employees', ['id' => $e->id, 'status' => 'waiting']);
    }

    public function test_the_stage_move_needs_a_real_reason_a_valid_stage_and_a_real_change(): void
    {
        $e = $this->existing('applicant', ['pipeline_stage' => 'damiin_needed']);
        $url = $this->url($e->id, '/move-stage');

        $this->as($this->hrManager)->postJson($url, ['stage' => 'need_training'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->hrManager)->postJson($url, ['stage' => 'need_training', 'reason' => 'short'])->assertStatus(422)->assertJsonPath('error_code', 'STAGE_MOVE_REASON_REQUIRED');
        $this->as($this->hrManager)->postJson($url, ['stage' => 'need_training', 'reason' => '          '])->assertStatus(422);
        $this->as($this->hrManager)->postJson($url, ['stage' => 'active', 'reason' => 'Make this person active please'])->assertStatus(422)->assertJsonValidationErrors('stage');
        $this->as($this->hrManager)->postJson($url, ['stage' => 'rejected', 'reason' => 'Make this person rejected please'])->assertStatus(422)->assertJsonValidationErrors('stage');
        $this->as($this->hrManager)->postJson($url, ['stage' => 'damiin_needed', 'reason' => 'Already there, nothing to change'])->assertStatus(422)->assertJsonPath('error_code', 'NO_STAGE_CHANGE');

        $this->assertDatabaseHas('employees', ['id' => $e->id, 'status' => 'applicant', 'pipeline_stage' => 'damiin_needed']);
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $e->id)->where('source', 'stage_move')->count());
    }

    public function test_a_stage_move_is_refused_for_new_registrations_office_staff_active_inactive_and_archived_people(): void
    {
        $body = ['stage' => 'waiting', 'reason' => 'Trying to put this person on the list'];

        $new = $this->register();
        $this->as($this->super)->postJson($this->url($new['id'], '/move-stage'), $body)->assertStatus(422)->assertJsonPath('error_code', 'NOT_AN_EXISTING_RECORD');
        $this->as($this->hrManager)->postJson($this->url($new['id'], '/move-stage'), $body)->assertStatus(422)->assertJsonPath('error_code', 'NOT_AN_EXISTING_RECORD');
        $this->assertDatabaseHas('employees', ['id' => $new['id'], 'status' => 'applicant', 'pipeline_stage' => 'damiin_needed']);

        $office = $this->makeEmployee('applicant');
        $office->update(['registration_origin' => 'office_staff']);
        $this->as($this->super)->postJson($this->url($office->id, '/move-stage'), $body)->assertStatus(422)->assertJsonPath('error_code', 'NOT_AN_EXISTING_RECORD');

        foreach (['active', 'inactive'] as $status) {
            $e = $this->existing($status);
            $this->as($this->super)->postJson($this->url($e->id, '/move-stage'), $body)->assertStatus(422)->assertJsonPath('error_code', 'STAGE_MOVE_NOT_FOR_ACTIVE_OR_INACTIVE');
            $this->assertDatabaseHas('employees', ['id' => $e->id, 'status' => $status]);
        }

        $archived = $this->existing('applicant');
        $archived->delete();
        $this->as($this->super)->postJson($this->url($archived->id, '/move-stage'), $body)->assertNotFound();
    }

    public function test_only_a_super_admin_or_an_hr_manager_may_move_a_stage_and_the_server_decides(): void
    {
        $e = $this->existing('applicant', ['pipeline_stage' => 'damiin_needed']);
        $body = ['stage' => 'need_training', 'reason' => 'Some reason that is long enough'];
        $url = $this->url($e->id, '/move-stage');

        $refused = [
            'hr_viewer' => $this->tokenFor(AdminRole::Sales, ['hr.view']),
            // Holds hr.manage but is not an HR Manager: the role is checked on the server too.
            'hr_manage_wrong_role' => $this->tokenFor(AdminRole::Sales, ['hr.view', 'hr.manage']),
            'hr_employee' => $this->tokenFor(AdminRole::HrEmployee, ['hr.view']),
            'marketing_manager' => $this->tokenFor(AdminRole::MarketingManager, ['marketing.view', 'marketing.manage', 'marketing.assign']),
            'mobile_app_manager' => $this->tokenFor(AdminRole::MobileAppManager, ['dashboard.view', 'customers.view']),
        ];

        foreach ($refused as $who => $token) {
            $this->as($token)->postJson($url, $body)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson($url, $body)->assertUnauthorized();
        $this->assertDatabaseHas('employees', ['id' => $e->id, 'status' => 'applicant', 'pipeline_stage' => 'damiin_needed']);
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $e->id)->where('source', 'stage_move')->count());

        $this->as($this->hrManager)->postJson($url, $body)->assertOk();
    }

    public function test_the_marketing_manager_cannot_change_a_status_or_assign_a_new_registration_either(): void
    {
        $marketing = $this->tokenFor(AdminRole::MarketingManager, ['marketing.view', 'marketing.manage', 'marketing.assign']);
        $new = $this->register();

        $this->as($marketing)->patchJson($this->url($new['id'], '/status'), ['status' => 'recruitment'])->assertForbidden();
        $this->as($marketing)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees", $this->assignBody(Employee::query()->findOrFail($new['id'])))->assertForbidden();
    }

    public function test_an_existing_person_listed_in_the_register_shows_that_they_are_not_held_to_the_workflow(): void
    {
        $e = $this->existing('waiting');
        $shown = $this->as($this->hrManager)->getJson($this->url($e->id))->assertOk()->json('data');

        $this->assertSame('existing', $shown['registration_origin']);
        $this->assertFalse($shown['registration_workflow']['applies']);
        $this->assertTrue($shown['registration_workflow']['eligible_for_waiting_and_company']);
        $this->assertSame([], $shown['registration_workflow']['steps']);
    }
}
