<?php

namespace Tests\Feature\Api\V1\Admin\Marketing;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\FollowUpHistory;
use App\Models\MarketingQuotation;
use App\Models\MarketingRecord;
use App\Models\MarketingTeam;
use App\Models\Permission;
use App\Models\ProjectDetail;
use App\Support\Marketing\WorkDateFollowUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner decisions of 2026-11-06 for Marketing. Synthetic data only.
 *
 *  - E-7   feedback is APPENDED to the saved feedback, never overwritten or silently removed.
 *  - E-8   a team is optional for a Marketing employee (E-9: no new mandatory backend team rule).
 *  - E-10  recording a PROJECT Work Date creates its follow-up automatically, once per record and date.
 *  - Q-STATUS  creating a quotation moves a PENDING record to QUOTATION.
 */
class MarketingOwnerDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
    }

    private function xarun(string $name = 'Decision Facility'): array
    {
        return $this->withToken($this->token)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => $name])->assertCreated()->json('data');
    }

    private function followUp(int $recordId, string $date = '2026-11-20'): array
    {
        return $this->withToken($this->token)->postJson("/api/v1/admin/marketing/records/{$recordId}/follow-ups", ['follow_up_date' => $date])->assertCreated()->json('data');
    }

    // ------------------------------------------------------------------------------------------------ E-7

    public function test_e7_feedback_added_from_a_follow_up_is_appended_and_every_entry_stays_in_the_follow_up_history(): void
    {
        $record = $this->xarun();
        $followUp = $this->followUp($record['id']);

        $this->withToken($this->token)->patchJson("/api/v1/admin/marketing/follow-ups/{$followUp['id']}/feedback", ['feedback' => 'First call: asked for a price list.'])->assertOk();
        $this->withToken($this->token)->patchJson("/api/v1/admin/marketing/follow-ups/{$followUp['id']}/feedback", ['feedback' => 'Second call: wants a visit on Monday.'])->assertOk();

        $this->assertSame("First call: asked for a price list.\n\nSecond call: wants a visit on Monday.", MarketingRecord::query()->findOrFail($record['id'])->feedback);
        $this->assertSame(
            ['First call: asked for a price list.', 'Second call: wants a visit on Monday.'],
            FollowUpHistory::query()->where('follow_up_id', $followUp['id'])->where('action', 'feedback_updated')->orderBy('id')->pluck('note')->all(),
        );
        $log = AuditLog::query()->where('entity_type', MarketingRecord::class)->where('entity_id', $record['id'])->latest('id')->firstOrFail();
        $this->assertSame('Second call: wants a visit on Monday.', $log->metadata['feedback_appended']);
    }

    public function test_e7_a_record_edit_may_add_after_the_saved_feedback_but_never_change_or_remove_it(): void
    {
        $record = $this->xarun();
        $followUp = $this->followUp($record['id']);
        $this->withToken($this->token)->patchJson("/api/v1/admin/marketing/follow-ups/{$followUp['id']}/feedback", ['feedback' => 'Saved feedback.'])->assertOk();

        // The same text again changes nothing.
        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/xarun/{$record['id']}", ['facility_name' => 'Decision Facility', 'feedback' => 'Saved feedback.'])->assertOk();
        $this->assertSame('Saved feedback.', MarketingRecord::query()->findOrFail($record['id'])->feedback);

        // More text after it is an append.
        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/xarun/{$record['id']}", ['facility_name' => 'Decision Facility', 'feedback' => "Saved feedback.\n\nAdded from the edit form."])->assertOk();
        $this->assertSame("Saved feedback.\n\nAdded from the edit form.", MarketingRecord::query()->findOrFail($record['id'])->feedback);

        // Changing the earlier text, replacing it, or clearing it is refused and nothing is written (not even the other fields of the same request).
        $before = MarketingRecord::query()->findOrFail($record['id'])->getAttributes();
        $auditBefore = AuditLog::query()->count();
        foreach (['Rewritten feedback.', '', null] as $attempt) {
            $this->withToken($this->token)->putJson("/api/v1/admin/marketing/xarun/{$record['id']}", ['facility_name' => 'Decision Facility', 'description' => 'Must not be saved either', 'feedback' => $attempt])
                ->assertStatus(422)->assertJsonPath('error_code', 'FEEDBACK_APPEND_ONLY');
        }
        $this->assertSame($before, MarketingRecord::query()->findOrFail($record['id'])->getAttributes());
        $this->assertSame($auditBefore, AuditLog::query()->count());

        // Not sending the field at all leaves the feedback alone and the rest of the edit works.
        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/xarun/{$record['id']}", ['facility_name' => 'Decision Facility', 'description' => 'Only the description changed'])->assertOk();
        $this->assertSame("Saved feedback.\n\nAdded from the edit form.", MarketingRecord::query()->findOrFail($record['id'])->feedback);
    }

    public function test_e7_the_project_edit_follows_the_same_rule(): void
    {
        $project = $this->withToken($this->token)->postJson('/api/v1/admin/marketing/project', ['responsible_party_type' => 'engineer', 'responsible_person_name' => 'Ahmed'])->assertCreated()->json('data');

        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/project/{$project['id']}", ['responsible_party_type' => 'engineer', 'feedback' => 'Project note one.'])->assertOk();
        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/project/{$project['id']}", ['responsible_party_type' => 'engineer', 'feedback' => 'Something else entirely.'])
            ->assertStatus(422)->assertJsonPath('error_code', 'FEEDBACK_APPEND_ONLY');
        $this->assertSame('Project note one.', MarketingRecord::query()->findOrFail($project['id'])->feedback);
    }

    // ------------------------------------------------------------------------------------------------ E-8 / E-9

    public function test_e8_a_marketing_employee_can_be_added_without_a_team_and_sees_only_work_of_their_own(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/admin/marketing/employees', [
            'full_name' => 'No Team Member', 'email' => 'noteam@example.test', 'phone' => '+252611000777', 'password' => 'Str0ng-pass-1',
        ])->assertCreated()->assertJsonPath('data.role', 'marketing_employee')->assertJsonPath('data.teams', []);

        $member = Admin::query()->findOrFail($response->json('data.id'));
        $this->assertSame(0, $member->marketingTeams()->count());
        $this->assertSame(3, DB::table('admin_permissions')->where('admin_id', $member->id)->count(), 'the same fixed baseline permissions as any team member');

        // A record that belongs to nobody in particular is invisible to the team-less member (the scope leaks nothing); their own work is visible.
        $other = $this->xarun('Somebody Else Facility');
        $this->app['auth']->forgetGuards();
        $memberToken = $member->createToken('t')->plainTextToken;
        $this->withToken($memberToken)->getJson("/api/v1/admin/marketing/records/{$other['id']}")->assertNotFound();
        $own = $this->withToken($memberToken)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'My Own Facility'])->assertCreated()->json('data');
        $this->withToken($memberToken)->getJson("/api/v1/admin/marketing/records/{$own['id']}")->assertOk();
    }

    public function test_e8_a_chosen_team_is_still_validated_and_still_works(): void
    {
        $team = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();

        $this->withToken($this->token)->postJson('/api/v1/admin/marketing/employees', [
            'full_name' => 'Team A Member', 'email' => 'teama@example.test', 'phone' => '+252611000778', 'password' => 'Str0ng-pass-1', 'team_id' => $team->id,
        ])->assertCreated()->assertJsonPath('data.teams.0.id', $team->id);

        $this->withToken($this->token)->postJson('/api/v1/admin/marketing/employees', [
            'full_name' => 'Bad Team Member', 'email' => 'badteam@example.test', 'phone' => '+252611000779', 'password' => 'Str0ng-pass-1', 'team_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors('team_id');
    }

    public function test_e9_records_still_need_no_team_on_the_server(): void
    {
        // The server never required a team on a record and still does not (frontend validation is the owner's choice).
        $this->withToken($this->token)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'No Team Facility'])->assertCreated()->assertJsonPath('data.assigned_team_id', null);
        $this->withToken($this->token)->postJson('/api/v1/admin/marketing/project', ['responsible_party_type' => 'engineer'])->assertCreated()->assertJsonPath('data.assigned_team_id', null);
    }

    // ------------------------------------------------------------------------------------------------ E-10

    private function project(array $overrides = []): array
    {
        return $this->withToken($this->token)->postJson('/api/v1/admin/marketing/project', array_replace(['responsible_party_type' => 'engineer', 'responsible_person_name' => 'Ahmed'], $overrides))->assertCreated()->json('data');
    }

    public function test_e10_a_work_date_recorded_with_the_project_creates_its_follow_up_once(): void
    {
        $project = $this->project(['fayadhowr_work_date' => '2026-12-01']);

        $followUps = FollowUp::query()->where('marketing_record_id', $project['id'])->get();
        $this->assertCount(1, $followUps);
        $this->assertSame('2026-12-01', $followUps->first()->follow_up_date->toDateString());
        $this->assertSame('scheduled', $followUps->first()->status->value);
        $this->assertSame('Created automatically from the Work Date of the record.', FollowUpHistory::query()->where('follow_up_id', $followUps->first()->id)->where('action', 'created')->value('note'));
        $this->assertSame($followUps->first()->id, AuditLog::query()->where('entity_type', MarketingRecord::class)->where('entity_id', $project['id'])->where('action', 'create')->firstOrFail()->metadata['work_date_follow_up_id']);

        // Saving the project again with the same date adds nothing.
        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/project/{$project['id']}", ['responsible_party_type' => 'engineer', 'fayadhowr_work_date' => '2026-12-01', 'description' => 'edited'])->assertOk();
        $this->assertSame(1, FollowUp::query()->where('marketing_record_id', $project['id'])->count());
    }

    public function test_e10_changing_the_work_date_adds_the_new_follow_up_and_keeps_the_old_one_and_its_history(): void
    {
        $project = $this->project(['fayadhowr_work_date' => '2026-12-01']);
        $first = FollowUp::query()->where('marketing_record_id', $project['id'])->firstOrFail();
        $firstHistory = FollowUpHistory::query()->where('follow_up_id', $first->id)->get()->map->getAttributes()->all();

        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/project/{$project['id']}", ['responsible_party_type' => 'engineer', 'fayadhowr_work_date' => '2026-12-09'])->assertOk();

        $this->assertSame(['2026-12-01', '2026-12-09'], FollowUp::query()->where('marketing_record_id', $project['id'])->orderBy('follow_up_date')->get()->map(fn (FollowUp $f) => $f->follow_up_date->toDateString())->all());
        $this->assertSame($first->getAttributes(), $first->fresh()->getAttributes(), 'the follow-up of the old date is untouched');
        $this->assertSame($firstHistory, FollowUpHistory::query()->where('follow_up_id', $first->id)->get()->map->getAttributes()->all());

        // Going back to a date that already has a follow-up (even a completed one) does not create a duplicate.
        $this->withToken($this->token)->patchJson("/api/v1/admin/marketing/follow-ups/{$first->id}/complete", [])->assertOk();
        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/project/{$project['id']}", ['responsible_party_type' => 'engineer', 'fayadhowr_work_date' => '2026-12-01'])->assertOk();
        $this->assertSame(2, FollowUp::query()->where('marketing_record_id', $project['id'])->count());
    }

    public function test_e10_a_project_without_a_work_date_and_a_follow_up_added_by_hand_are_unchanged(): void
    {
        $project = $this->project();
        $this->assertSame(0, FollowUp::query()->where('marketing_record_id', $project['id'])->count());

        $this->withToken($this->token)->postJson("/api/v1/admin/marketing/records/{$project['id']}/follow-ups", ['follow_up_date' => '2026-12-05'])->assertCreated();
        $this->assertNull(FollowUpHistory::query()->where('action', 'created')->value('note'), 'a hand-made follow-up has no automatic note');

        // Clearing the work date does not delete anything.
        $withDate = $this->project(['fayadhowr_work_date' => '2026-12-03']);
        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/project/{$withDate['id']}", ['responsible_party_type' => 'engineer', 'fayadhowr_work_date' => null])->assertOk();
        $this->assertSame(1, FollowUp::query()->where('marketing_record_id', $withDate['id'])->count());
    }

    // ------------------------------------------------------------------------------------------------ Q-STATUS

    public function test_q_status_creating_a_quotation_moves_a_pending_record_to_quotation_once(): void
    {
        $record = $this->xarun();
        $this->assertSame('pending', MarketingRecord::query()->findOrFail($record['id'])->status->value);

        $this->withToken($this->token)->postJson("/api/v1/admin/marketing/records/{$record['id']}/quotations", ['amount' => 500])->assertCreated();
        $this->assertSame('quotation', MarketingRecord::query()->findOrFail($record['id'])->status->value);
        $statusChanges = fn () => AuditLog::query()->where('entity_type', MarketingRecord::class)->where('entity_id', $record['id'])->where('description', 'like', '%status changed%')->count();
        $this->assertSame(1, $statusChanges());
        $log = AuditLog::query()->where('entity_type', MarketingRecord::class)->where('entity_id', $record['id'])->where('description', 'like', '%status changed%')->firstOrFail();
        $this->assertSame(['pending', 'quotation'], [$log->metadata['from_status'], $log->metadata['to_status']]);

        // A second quotation is one more quotation row; the record is already Quotation, so no second status change.
        $this->withToken($this->token)->postJson("/api/v1/admin/marketing/records/{$record['id']}/quotations", ['amount' => 700])->assertCreated();
        $this->assertSame(2, MarketingQuotation::query()->where('marketing_record_id', $record['id'])->count());
        $this->assertSame(1, $statusChanges());
        $this->assertSame('quotation', MarketingRecord::query()->findOrFail($record['id'])->status->value);
    }

    public function test_q_status_a_done_or_cancelled_record_is_never_moved_back_by_a_new_quotation(): void
    {
        foreach (['done', 'cancelled'] as $status) {
            $record = $this->xarun("Closed {$status} Facility");
            $this->withToken($this->token)->patchJson("/api/v1/admin/marketing/records/{$record['id']}/status", ['status' => $status])->assertOk();

            $this->withToken($this->token)->postJson("/api/v1/admin/marketing/records/{$record['id']}/quotations", ['amount' => 100])->assertCreated();

            $this->assertSame($status, MarketingRecord::query()->findOrFail($record['id'])->status->value);
            $this->assertSame(1, MarketingQuotation::query()->where('marketing_record_id', $record['id'])->count());
        }
    }

    public function test_q_status_the_marketing_permission_boundary_for_creating_a_quotation_is_unchanged(): void
    {
        $record = $this->xarun();
        $viewer = Admin::factory()->create(['role' => AdminRole::MarketingEmployee]);
        DB::table('admin_permissions')->insert(['admin_id' => $viewer->id, 'permission_id' => Permission::query()->where('key', 'marketing.view')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $this->app['auth']->forgetGuards();

        $this->withToken($viewer->createToken('t')->plainTextToken)->postJson("/api/v1/admin/marketing/records/{$record['id']}/quotations", ['amount' => 100])->assertStatus(403);
        $this->assertSame('pending', MarketingRecord::query()->findOrFail($record['id'])->status->value);
    }

    // ------------------------------------------------------------------------------------------------ E-10, Final Remediation Audit: PROJECT versus XARUN

    public function test_e10_xarun_has_no_work_date_by_design_so_it_never_creates_one_or_a_follow_up(): void
    {
        // The SRS gives XARUN and PROJECT different forms; "Fayadhowr Work Date" belongs to the PROJECT form only (HRM_MARKETING_SRS.md section 10).
        $created = $this->withToken($this->token)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Dated Facility', 'fayadhowr_work_date' => '2026-12-09'])->assertCreated();
        $recordId = $created->json('data.id');

        $this->assertNull($created->json('data.xarun.fayadhowr_work_date'), 'a XARUN record carries no Work Date');
        $this->assertArrayNotHasKey('fayadhowr_work_date', (array) $created->json('data.xarun'));
        $this->assertSame(0, FollowUp::query()->where('marketing_record_id', $recordId)->count());

        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/xarun/{$recordId}", ['facility_name' => 'Dated Facility', 'fayadhowr_work_date' => '2026-12-09'])->assertOk();
        $this->assertSame(0, FollowUp::query()->where('marketing_record_id', $recordId)->count());
    }

    public function test_e10_asking_for_the_same_follow_up_twice_never_adds_a_second_one(): void
    {
        $project = $this->project();
        $record = MarketingRecord::query()->findOrFail($project['id']);
        $actor = Admin::query()->firstOrFail();

        $first = WorkDateFollowUp::ensure($record, '2026-12-05', $actor);
        $second = WorkDateFollowUp::ensure($record, '2026-12-05', $actor);
        $third = WorkDateFollowUp::ensure($record, '2026-12-05 00:00:00', $actor); // the same day written another way

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertNull($third);
        $this->assertSame(1, FollowUp::query()->where('marketing_record_id', $project['id'])->count());
        $this->assertSame(1, FollowUpHistory::query()->where('follow_up_id', $first->id)->where('action', 'created')->count());
    }

    public function test_e10_a_project_saved_before_the_rule_is_not_back_filled_by_an_unchanged_save(): void
    {
        // Only a Work Date that is recorded or CHANGED creates a follow-up. A record that already had a date before this rule is not touched by a save that
        // leaves the date as it is (a back-fill of existing records would be a data operation of its own, needing its own approval).
        $project = $this->project();
        ProjectDetail::query()->where('marketing_record_id', $project['id'])->update(['fayadhowr_work_date' => '2026-12-05']);

        $this->withToken($this->token)->putJson("/api/v1/admin/marketing/project/{$project['id']}", ['responsible_party_type' => 'engineer', 'fayadhowr_work_date' => '2026-12-05', 'description' => 'edited'])->assertOk();

        $this->assertSame(0, FollowUp::query()->where('marketing_record_id', $project['id'])->count());
    }
}
