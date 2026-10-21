<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The archived employees (removed from every working list) can be found in their own view, opened and corrected by someone allowed to - and an
 * edit never brings them back: they stay archived, keep their status, assignments, history and ids. Status-changing actions stay unavailable.
 */
class ArchivedEmployeesTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $manager;

    private string $viewer;

    private Employee $archived;

    private Employee $current;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);

        $this->current = $this->makeEmployee('active', 'permanent', '615100001');
        $this->current->forceFill(['full_name' => 'Current Person'])->save();
        $this->archived = $this->makeEmployee('inactive', 'permanent', '615100002');
        $this->archived->forceFill(['full_name' => 'Archived Person'])->save();
        $this->archived->delete();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function stored(): Employee
    {
        return Employee::withTrashed()->findOrFail($this->archived->id);
    }

    public function test_the_usual_lists_leave_the_archived_people_out_and_the_archived_view_shows_only_them(): void
    {
        $usual = $this->as($this->viewer)->getJson('/api/v1/admin/hr/employees')->assertOk();
        $this->assertSame([$this->current->id], collect($usual->json('data'))->pluck('id')->all());
        $this->assertFalse($usual->json('data.0.is_archived'));

        $archived = $this->as($this->viewer)->getJson('/api/v1/admin/hr/employees?archived=true')->assertOk();
        $this->assertSame(1, $archived->json('meta.total'));
        $this->assertSame([$this->archived->id], collect($archived->json('data'))->pluck('id')->all());
        $this->assertTrue($archived->json('data.0.is_archived'));
        $this->assertNotNull($archived->json('data.0.archived_at'));
        $this->assertSame('inactive', $archived->json('data.0.status'), 'the stored status is shown as it is');

        // archived=false is the usual list; the archived view takes the normal search.
        $this->assertSame([$this->current->id], collect($this->as($this->viewer)->getJson('/api/v1/admin/hr/employees?archived=false')->json('data'))->pluck('id')->all());
        $this->assertSame([$this->archived->id], collect($this->as($this->viewer)->getJson('/api/v1/admin/hr/employees?archived=true&search=archived')->json('data'))->pluck('id')->all());
        $this->assertSame([], $this->as($this->viewer)->getJson('/api/v1/admin/hr/employees?archived=true&search=current')->json('data'));
    }

    public function test_the_archived_view_needs_the_same_hr_permission_as_the_other_lists(): void
    {
        $this->as($this->noPermissionToken())->getJson('/api/v1/admin/hr/employees?archived=true')->assertForbidden();
        $this->as($this->noPermissionToken())->getJson("/api/v1/admin/hr/employees/{$this->archived->id}")->assertForbidden();
    }

    public function test_an_archived_person_can_be_opened_with_the_read_only_pages_of_the_detail_screen(): void
    {
        $assignment = $this->makeAssignment($this->archived, $this->makeCompany('Alpha Hospital'));
        $id = $this->archived->id;

        $show = $this->as($this->viewer)->getJson("/api/v1/admin/hr/employees/{$id}")->assertOk();
        $this->assertTrue($show->json('data.is_archived'));
        $this->assertSame('Archived Person', $show->json('data.full_name'));

        $this->as($this->viewer)->getJson("/api/v1/admin/hr/employees/{$id}/contracts")->assertOk();
        // Nothing was recorded for these two: each page answers with its own "none recorded yet" code, i.e. the archived person WAS found.
        $this->as($this->viewer)->getJson("/api/v1/admin/hr/employees/{$id}/guarantor")->assertNotFound()->assertJsonPath('error_code', 'EMPLOYEE_GUARANTOR_NOT_FOUND');
        $this->as($this->viewer)->getJson("/api/v1/admin/hr/employees/{$id}/uniform")->assertNotFound()->assertJsonPath('error_code', 'EMPLOYEE_UNIFORM_NOT_FOUND');
        $this->as($this->viewer)->getJson("/api/v1/admin/hr/employees/{$id}/payroll-summary?work_assignment_id={$assignment->id}&period=2026-09")->assertOk();
    }

    public function test_editing_an_archived_person_corrects_the_details_and_never_brings_them_back(): void
    {
        $company = $this->makeCompany('Alpha Hospital');
        $assignment = $this->makeAssignment($this->archived, $company);
        $before = $this->stored();
        $id = $this->archived->id;

        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$id}", ['full_name' => '  Corrected   Name ', 'notes' => 'Corrected from the archived view'])
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.full_name', 'Corrected Name')->assertJsonPath('data.is_archived', true);

        $after = $this->stored();
        $this->assertNotNull($after->deleted_at, 'still archived');
        $this->assertEquals($before->deleted_at, $after->deleted_at, 'the archive date did not move');
        $this->assertSame('Corrected Name', $after->full_name);
        $this->assertSame($before->status, $after->status, 'the employment status is untouched');
        $this->assertSame($before->employee_number, $after->employee_number);
        $this->assertSame($before->phone, $after->phone);
        $this->assertSame($before->pipeline_stage, $after->pipeline_stage);
        $this->assertSame('active', $assignment->fresh()->status->value, 'the company assignment is untouched');
        $this->assertSame($company->id, $assignment->fresh()->client_company_id);
        $this->assertNull($assignment->fresh()->end_date);

        $log = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $id)->latest('id')->firstOrFail();
        $this->assertSame(['from' => 'Archived Person', 'to' => 'Corrected Name'], $log->metadata['full_name']);

        // It still does not appear in the usual lists, only in the archived view.
        $this->assertNotContains($id, collect($this->as($this->manager)->getJson('/api/v1/admin/hr/employees')->json('data'))->pluck('id')->all());
        $this->assertContains($id, collect($this->as($this->manager)->getJson('/api/v1/admin/hr/employees?archived=true')->json('data'))->pluck('id')->all());
    }

    public function test_an_edit_cannot_change_the_status_and_a_viewer_cannot_edit(): void
    {
        $id = $this->archived->id;

        // "status" is not an editable field: it is ignored, the person stays inactive and archived.
        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$id}", ['notes' => 'x', 'status' => 'active', 'pipeline_stage' => 'waiting'])->assertOk();
        $this->assertSame('inactive', $this->stored()->status->value);
        $this->assertNotNull($this->stored()->deleted_at);

        $this->as($this->viewer)->putJson("/api/v1/admin/hr/employees/{$id}", ['notes' => 'changed by a viewer'])->assertForbidden();
        $this->assertNotSame('changed by a viewer', $this->stored()->notes);
    }

    public function test_the_actions_that_change_status_or_history_stay_unavailable_for_an_archived_person(): void
    {
        $id = $this->archived->id;
        $base = "/api/v1/admin/hr/employees/{$id}";

        $this->as($this->manager)->patchJson("{$base}/status", ['status' => 'active'])->assertNotFound();
        $this->as($this->manager)->postJson("{$base}/move-stage", ['pipeline_stage' => 'waiting'])->assertNotFound();
        $this->as($this->manager)->patchJson("{$base}/rehire", [])->assertNotFound();
        $this->as($this->manager)->patchJson("{$base}/separate", [])->assertNotFound();
        $this->as($this->manager)->postJson("{$base}/attendance", [])->assertNotFound();
        $this->as($this->manager)->postJson("{$base}/payments", [])->assertNotFound();

        $stored = $this->stored();
        $this->assertSame('inactive', $stored->status->value);
        $this->assertNotNull($stored->deleted_at);
    }
}
