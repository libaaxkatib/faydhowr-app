<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Restoring an archived employee is an explicit, permissioned, audited action that changes only the archive mark: the person returns to the
 * state they already have (waiting / an applicant stage / former), keeps their id, history, documents and assignments, and nothing else - no
 * other person, no permission - is touched. A second restore, or restoring someone who is not archived, is refused and leaves no audit record.
 */
class RestoreArchivedEmployeeTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $manager;

    private string $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function restoreUrl(Employee $employee): string
    {
        return "/api/v1/admin/hr/employees/{$employee->id}/restore";
    }

    private function archived(string $status = 'waiting', ?string $phone = null, ?string $stage = null): Employee
    {
        $employee = $this->makeEmployee($status, 'permanent', $phone);
        if ($stage !== null) {
            $employee->forceFill(['pipeline_stage' => $stage])->save();
        }
        $employee->delete();

        return $employee;
    }

    private function auditRows(Employee $employee)
    {
        return AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $employee->id)->where('description', 'like', '%restored from the archive%');
    }

    private function idsOf(string $query): array
    {
        return collect($this->as($this->manager)->getJson('/api/v1/admin/hr/employees?'.$query)->assertOk()->json('data'))->pluck('id')->all();
    }

    // ------------------------------------------------------------------ 1 + 4: it works and the person lands in the right view

    public function test_an_authorized_user_restores_an_archived_person_into_the_view_that_matches_the_status_they_already_have(): void
    {
        $waiting = $this->archived('waiting');
        $applicant = $this->archived('applicant', null, 'need_training');
        $former = $this->archived('inactive');

        foreach ([$waiting, $applicant, $former] as $person) {
            $this->assertContains($person->id, $this->idsOf('archived=true'), 'listed in the archived view first');
            $this->assertNotContains($person->id, $this->idsOf(''), 'and in no working list');
        }

        $response = $this->as($this->manager)->postJson($this->restoreUrl($waiting))->assertOk()
            ->assertJsonPath('data.id', $waiting->id)->assertJsonPath('data.is_archived', false)->assertJsonPath('data.archived_at', null)->assertJsonPath('data.status', 'waiting');
        $this->assertNotNull($response->json('data.employee_number'));
        $this->as($this->manager)->postJson($this->restoreUrl($applicant))->assertOk()->assertJsonPath('data.status', 'applicant')->assertJsonPath('data.pipeline_stage', 'need_training');
        $this->as($this->manager)->postJson($this->restoreUrl($former))->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->assertContains($waiting->id, $this->idsOf('status=waiting'), 'back in the Waiting list');
        $this->assertContains($applicant->id, $this->idsOf('pipeline_stage=need_training'), 'back in the Need Training queue');
        $this->assertContains($former->id, $this->idsOf('status=inactive'), 'back among the former employees');
        $this->assertContains($waiting->id, $this->idsOf(''), 'and in the main list');
        foreach ([$waiting, $applicant, $former] as $person) {
            $this->assertNotContains($person->id, $this->idsOf('archived=true'), 'no longer in the archived view');
        }
    }

    // ------------------------------------------------------------------ 2 + 3: authorization, also by calling the endpoint directly

    public function test_nobody_without_the_manage_permission_can_restore_even_by_calling_the_endpoint_directly(): void
    {
        $person = $this->archived('waiting');
        $marketing = $this->tokenForRoleWith(AdminRole::MarketingManager, ['marketing.view', 'marketing.manage']);
        $hrEmployee = $this->tokenForRoleWith(AdminRole::HrEmployee, ['hr.view']);

        foreach ([$this->viewer, $this->noPermissionToken(), $marketing, $hrEmployee] as $token) {
            $this->as($token)->postJson($this->restoreUrl($person))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson($this->restoreUrl($person))->assertUnauthorized();

        $this->assertNotNull(Employee::withTrashed()->find($person->id)->deleted_at, 'still archived after every refused call');
        $this->assertSame(0, $this->auditRows($person)->count(), 'a refused call leaves no audit record');
        // Authorization comes before the lookup: an unknown id gives the same answer to someone not allowed.
        $this->as($this->viewer)->postJson('/api/v1/admin/hr/employees/999999/restore')->assertForbidden();
    }

    public function test_a_super_admin_can_restore_too(): void
    {
        $person = $this->archived('waiting');
        [, $super] = $this->superAdminToken();

        $this->as($super)->postJson($this->restoreUrl($person))->assertOk();
        $this->assertNull(Employee::withTrashed()->find($person->id)->deleted_at);
    }

    // ------------------------------------------------------------------ 5: identity, history, documents and relationships stay intact

    public function test_a_restore_changes_nothing_but_the_archive_mark(): void
    {
        $person = $this->archived('applicant', '0615000123', 'need_training');
        $person->forceFill(['payment_account_number' => '000987', 'notes' => 'Kept note'])->saveQuietly();
        $company = $this->makeCompany('Alpha Hospital');
        $assignment = $this->makeAssignment($person, $company);
        $document = EmployeeDocument::query()->create([
            'employee_id' => $person->id, 'admin_id' => Admin::factory()->superAdmin()->create()->id, 'employee_document_category_id' => null,
            'file_name' => 'scan.pdf', 'file_type' => 'pdf', 'file_size' => 10, 'file_path' => 'employees/'.$person->id.'/documents/a.pdf',
            'disk' => 'r2_documents', 'sha256' => hash('sha256', 'x'), 'verification_status' => 'pending', 'is_current' => true,
        ]);
        $history = EmployeeStatusHistory::query()->create(['employee_id' => $person->id, 'from_status' => 'applicant', 'to_status' => 'applicant', 'note' => 'Registered']);

        $before = Employee::withTrashed()->find($person->id)->getAttributes();
        $relations = fn () => [
            EmployeeDocument::query()->where('employee_id', $person->id)->pluck('id')->all(),
            EmployeeStatusHistory::query()->where('employee_id', $person->id)->pluck('id')->all(),
            DB::table('employee_work_assignments')->where('employee_id', $person->id)->get()->map(fn ($r) => (array) $r)->all(),
        ];
        $relationsBefore = $relations();

        $this->as($this->manager)->postJson($this->restoreUrl($person))->assertOk();

        $after = Employee::query()->find($person->id)->getAttributes();
        unset($before['deleted_at'], $before['updated_at'], $after['deleted_at'], $after['updated_at']);
        $this->assertSame($before, $after, 'every column except the archive mark is exactly as it was');
        $this->assertSame($relationsBefore, $relations(), 'documents, history and assignments are the same rows, unchanged');
        $this->assertSame([$document->id], $relations()[0]);
        $this->assertSame([$history->id], $relations()[1]);
        $this->assertSame($assignment->id, DB::table('employee_work_assignments')->where('employee_id', $person->id)->value('id'));
        $this->assertSame('0615000123', Employee::query()->find($person->id)->phone);
        $this->assertSame('000987', Employee::query()->find($person->id)->payment_account_number, 'text kept as written');
    }

    // ------------------------------------------------------------------ 6: invalid or repeated restores

    public function test_a_second_restore_or_restoring_someone_who_is_not_archived_is_refused_and_leaves_no_audit_record(): void
    {
        $person = $this->archived('waiting');
        $current = $this->makeEmployee('waiting');

        $this->as($this->manager)->postJson($this->restoreUrl($person))->assertOk();
        $this->as($this->manager)->postJson($this->restoreUrl($person))->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_NOT_ARCHIVED');
        $this->as($this->manager)->postJson($this->restoreUrl($current))->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_NOT_ARCHIVED');
        $this->as($this->manager)->postJson('/api/v1/admin/hr/employees/999999/restore')->assertNotFound();

        $this->assertSame(1, $this->auditRows($person)->count(), 'exactly one record, from the one real restore');
        $this->assertSame(0, $this->auditRows($current)->count());
        $this->assertNull(Employee::query()->find($person->id)->deleted_at);

        // The optional inputs are validated like everywhere else.
        $other = $this->archived('waiting');
        $this->as($this->manager)->postJson($this->restoreUrl($other), ['reason' => str_repeat('x', 501)])->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'errors');
        $this->as($this->manager)->postJson($this->restoreUrl($other), ['confirm_duplicate' => true])->assertUnprocessable()->assertJsonValidationErrors(['duplicate_reason'], 'errors');
        $this->assertNotNull(Employee::withTrashed()->find($other->id)->deleted_at);
    }

    // ------------------------------------------------------------------ 7: the audit record

    public function test_the_audit_record_names_who_restored_whom_and_the_lifecycle_before_and_after(): void
    {
        $person = $this->archived('applicant', '0615111222', 'need_training');
        $actorId = (int) PersonalAccessToken::findToken($this->manager)->tokenable_id;

        $this->as($this->manager)->postJson($this->restoreUrl($person), ['reason' => 'Returned after the file review'])->assertOk();

        $log = $this->auditRows($person)->sole();
        $this->assertSame($actorId, (int) $log->admin_id);
        $this->assertSame('update', $log->action->value);
        $this->assertSame($person->id, (int) $log->entity_id);
        $this->assertNotNull($log->created_at);
        $this->assertStringContainsString($person->employee_number, $log->description);
        $this->assertTrue($log->metadata['restored_from_archive']);
        $this->assertNotNull($log->metadata['archived_at']);
        $this->assertSame(['archived' => true, 'status' => 'applicant', 'pipeline_stage' => 'need_training'], $log->metadata['before']);
        $this->assertSame(['archived' => false, 'status' => 'applicant', 'pipeline_stage' => 'need_training'], $log->metadata['after']);
        $this->assertSame('Returned after the file review', $log->metadata['reason']);
        $this->assertStringNotContainsString('0615111222', json_encode($log->metadata).$log->description, 'no phone number in the audit trail');
    }

    // ------------------------------------------------------------------ 8: nothing else changes

    public function test_a_restore_touches_no_other_person_and_grants_no_permission(): void
    {
        $person = $this->archived('waiting');
        $otherArchived = $this->archived('waiting');
        $current = $this->makeEmployee('active');
        $currentBefore = Employee::query()->find($current->id)->getAttributes();
        $permissionsBefore = [DB::table('admin_permissions')->count(), DB::table('admin_role_permissions')->count(), DB::table('permissions')->count()];
        $roleBefore = Admin::query()->pluck('role', 'id')->map(fn ($r) => $r->value ?? $r)->all();

        $this->as($this->manager)->postJson($this->restoreUrl($person))->assertOk();

        $this->assertNotNull(Employee::withTrashed()->find($otherArchived->id)->deleted_at, 'another archived person stays archived');
        $this->assertSame($currentBefore, Employee::query()->find($current->id)->getAttributes(), 'a current person is untouched');
        $this->assertSame($permissionsBefore, [DB::table('admin_permissions')->count(), DB::table('admin_role_permissions')->count(), DB::table('permissions')->count()]);
        $this->assertSame($roleBefore, Admin::query()->pluck('role', 'id')->map(fn ($r) => $r->value ?? $r)->all());
        // The viewer still cannot do what the manager could.
        $this->as($this->viewer)->postJson($this->restoreUrl($otherArchived))->assertForbidden();
    }

    // ------------------------------------------------------------------ duplicate phone

    public function test_a_phone_that_a_current_employee_uses_needs_a_reasoned_confirmation_and_another_archived_record_does_not_block(): void
    {
        $person = $this->archived('waiting', '0615777001');
        $twin = $this->makeEmployee('waiting', 'permanent', '0615777001');
        $archivedTwin = $this->archived('waiting', '0615888002');
        $this->makeEmployee('waiting', 'permanent', '0615888002')->delete();

        $refused = $this->as($this->manager)->postJson($this->restoreUrl($person))->assertUnprocessable()->assertJsonPath('error_code', 'DUPLICATE_EMPLOYEE_PHONE');
        $this->assertSame([$twin->id], collect($refused->json('errors.matches'))->pluck('id')->all());
        $this->assertNotNull(Employee::withTrashed()->find($person->id)->deleted_at, 'still archived');
        $this->assertSame(0, $this->auditRows($person)->count());

        $this->as($this->manager)->postJson($this->restoreUrl($person), ['confirm_duplicate' => true, 'duplicate_reason' => 'short'])->assertUnprocessable();
        $this->as($this->manager)->postJson($this->restoreUrl($person), ['confirm_duplicate' => true, 'duplicate_reason' => 'A different person who shares a family phone'])->assertOk();

        $log = $this->auditRows($person)->sole();
        $this->assertTrue($log->metadata['duplicate_override']);
        $this->assertSame([$twin->id], $log->metadata['matched_employee_ids']);

        // The other archived record with the same number is not a current employee, so it is no obstacle.
        $this->as($this->manager)->postJson($this->restoreUrl($archivedTwin))->assertOk();
    }

    // ------------------------------------------------------------------ 9: existing restrictions

    public function test_the_restrictions_on_an_archived_person_hold_until_the_restore_and_end_with_it(): void
    {
        $person = $this->archived('waiting');
        $base = "/api/v1/admin/hr/employees/{$person->id}";

        // Archived: details can be corrected, nothing that changes the status or history is reachable, and an edit does not restore.
        $this->as($this->manager)->putJson($base, ['notes' => 'Corrected while archived'])->assertOk()->assertJsonPath('data.is_archived', true);
        $this->as($this->manager)->patchJson("{$base}/status", ['status' => 'applicant'])->assertNotFound();
        $this->as($this->manager)->postJson("{$base}/attendance", [])->assertNotFound();
        $this->assertNotNull(Employee::withTrashed()->find($person->id)->deleted_at);
        $this->assertSame(0, $this->auditRows($person)->count(), 'an edit is not a restore');

        $this->as($this->manager)->postJson("{$base}/restore")->assertOk();

        // Restored: the ordinary actions exist again for this person (they are answered, not "not found").
        $this->assertNotSame(404, $this->as($this->manager)->patchJson("{$base}/status", ['status' => 'applicant'])->status());
        $this->assertNotSame(404, $this->as($this->manager)->postJson("{$base}/attendance", [])->status());
        $this->as($this->manager)->putJson($base, ['notes' => 'Corrected after the restore'])->assertOk()->assertJsonPath('data.is_archived', false);
    }
}
