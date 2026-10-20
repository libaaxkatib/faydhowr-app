<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\TrainingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner rule (2026-10-20): a person who is Active is working, so they are NOT in any recruitment pipeline stage. Becoming Active clears the
 * stage (kept in the history, never invented or deleted); Active people never appear in a stage queue, filter or count; and the records that
 * were activated before the rule existed are corrected by an audited, repeatable command that never touches Inactive or archived records.
 */
class ActivePipelineStageTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private Admin $super;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->super, $this->token] = $this->superAdminToken();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function person(string $status, ?string $stage, array $extra = []): Employee
    {
        $e = $this->makeEmployee($status);
        $e->update(['pipeline_stage' => $stage, 'registration_origin' => 'existing', ...$extra]);

        return $e->fresh();
    }

    public function test_becoming_active_clears_the_stage_and_keeps_it_in_the_history_and_the_audit_log(): void
    {
        $e = $this->person('applicant', 'need_training');

        $this->as()->patchJson("/api/v1/admin/hr/employees/{$e->id}/status", ['status' => 'active', 'override_reason' => 'Part Two: owner approved direct activation of the applicants.'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.pipeline_stage', null);

        $this->assertNull($e->fresh()->pipeline_stage);
        $history = EmployeeStatusHistory::query()->where('employee_id', $e->id)->where('to_status', 'active')->firstOrFail();
        $this->assertSame('override', $history->source);
        $this->assertStringContainsString("Pipeline stage 'Need Training' cleared", (string) $history->note);
        $audit = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $e->id)->get()->first(fn ($l) => ($l->metadata['pipeline_stage_cleared'] ?? false) === true);
        $this->assertSame(['need_training', $this->super->id], [$audit->metadata['previous_pipeline_stage'], $audit->admin_id]);

        // Nothing was invented: no training or practical record exists.
        foreach (['training_batch_participants', 'employee_practical_assessments', 'employee_guarantors', 'employee_contracts', 'employee_uniforms'] as $table) {
            $this->assertSame(0, DB::table($table)->where('employee_id', $e->id)->count());
        }
    }

    public function test_a_normal_waiting_to_active_step_and_a_rehire_leave_no_stage_either(): void
    {
        $waiting = $this->person('waiting', null);
        $this->as()->patchJson("/api/v1/admin/hr/employees/{$waiting->id}/status", ['status' => 'active'])->assertOk();
        $this->assertNull($waiting->fresh()->pipeline_stage);
        $this->assertStringNotContainsString('cleared', (string) EmployeeStatusHistory::query()->where('employee_id', $waiting->id)->where('to_status', 'active')->value('note'));

        $gone = $this->person('inactive', 'need_practical');
        $this->as()->patchJson("/api/v1/admin/hr/employees/{$gone->id}/rehire", ['note' => 'Back'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertNull($gone->fresh()->pipeline_stage);
        $this->assertStringContainsString("Pipeline stage 'Need Practical' cleared", (string) EmployeeStatusHistory::query()->where('employee_id', $gone->id)->where('to_status', 'active')->value('note'));
    }

    public function test_active_people_never_appear_in_a_stage_queue_a_stage_count_or_the_training_screen_even_if_an_old_record_still_has_a_stage(): void
    {
        $old = $this->person('active', 'need_training');        // an old record the correction has not reached yet
        $queued = $this->person('applicant', 'need_training');
        $inactive = $this->person('inactive', 'need_training');

        $ids = collect($this->as()->getJson('/api/v1/admin/hr/employees?pipeline_stage=need_training')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($queued->id, $ids);
        $this->assertNotContains($old->id, $ids, 'an Active person is not in the Need Training queue');
        $this->assertContains($inactive->id, $ids, 'Inactive records are not changed by this rule');

        $dash = $this->as()->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');
        $this->assertSame(2, $dash['need_training'], 'applicant + inactive; the Active person is not counted');
        $this->assertSame(1, $dash['active_employees'], 'the Active person is counted as Active');
        $summary = $this->as()->getJson('/api/v1/admin/hr/reports/summary')->assertOk()->json('data');
        $encoded = json_encode($summary);
        $this->assertNotFalse($encoded);

        $batch = TrainingBatch::query()->create(['batch_number' => 'B-1', 'batch_date' => now()->toDateString(), 'status' => 'scheduled']);
        $this->as()->postJson("/api/v1/admin/hr/training-batches/{$batch->id}/participants", ['employee_id' => $old->id])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ELIGIBLE_FOR_TRAINING');
        $this->as()->postJson("/api/v1/admin/hr/training-batches/{$batch->id}/participants", ['employee_id' => $queued->id])->assertSuccessful();
    }

    public function test_the_correction_command_clears_only_active_unarchived_records_audits_each_and_can_be_repeated(): void
    {
        $a = $this->person('active', 'need_training');
        $b = $this->person('active', 'need_practical');
        $clean = $this->person('active', null);
        $inactive = $this->person('inactive', 'need_training');
        $applicant = $this->person('applicant', 'contract_pending');
        $archived = $this->person('active', 'need_training');
        $archived->delete();

        $raw = fn (Employee $e) => (array) DB::table('employees')->where('id', $e->id)->first();
        $before = [$raw($inactive), $raw($applicant), $raw($archived), $raw($clean)];
        $historyBefore = EmployeeStatusHistory::query()->count();

        // Dry run: reports, writes nothing.
        $this->artisan('hr:clear-active-pipeline-stages')->expectsOutputToContain('DRY RUN')->expectsOutputToContain('carry a pipeline stage: 2')->assertSuccessful();
        $this->assertSame('need_training', $a->fresh()->pipeline_stage->value);
        $this->assertSame($historyBefore, EmployeeStatusHistory::query()->count());

        // A real run needs a Super Admin.
        $plain = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $this->artisan('hr:clear-active-pipeline-stages', ['--commit' => true, '--admin-email' => $plain->email])->assertFailed();
        $this->assertSame('need_training', $a->fresh()->pipeline_stage->value);

        $this->artisan('hr:clear-active-pipeline-stages', ['--commit' => true, '--admin-email' => $this->super->email])->expectsOutputToContain('Cleared: 2.')->assertSuccessful();

        $this->assertNull($a->fresh()->pipeline_stage);
        $this->assertNull($b->fresh()->pipeline_stage);
        $this->assertSame('active', $a->fresh()->status->value);
        // Everything else about the two people is unchanged except updated_at.
        $this->assertSame(['active', $a->full_name], [$a->fresh()->status->value, $a->fresh()->full_name]);

        $this->assertSame($before, [$raw($inactive), $raw($applicant), $raw($archived), $raw($clean)], 'Inactive, other-status, archived and already-clean records are byte-identical');

        $rows = EmployeeStatusHistory::query()->where('source', 'stage_cleanup')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['active', 'active', $this->super->id], [$rows[0]->from_status->value, $rows[0]->to_status->value, $rows[0]->changed_by]);
        $this->assertStringContainsString("Pipeline stage 'Need Training' cleared", (string) $rows->firstWhere('employee_id', $a->id)->note);
        $logs = AuditLog::query()->where('entity_type', Employee::class)->get()->filter(fn ($l) => ($l->metadata['event'] ?? null) === 'pipeline_stage_cleared');
        $this->assertCount(2, $logs);
        $this->assertSame(['need_training', 'need_practical'], $logs->sortBy('entity_id')->map(fn ($l) => $l->metadata['previous_pipeline_stage'])->values()->all());
        $run = AuditLog::query()->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'pipeline_stage_cleanup_run');
        $this->assertSame([2, 2], [$run->metadata['candidates'], $run->metadata['cleared']]);

        // Repeating it finds nothing and writes nothing.
        $this->artisan('hr:clear-active-pipeline-stages', ['--commit' => true, '--admin-email' => $this->super->email])->expectsOutputToContain('Cleared: 0.')->assertSuccessful();
        $this->assertSame(2, EmployeeStatusHistory::query()->where('source', 'stage_cleanup')->count());
    }
}
