<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner decision TRANSLATION-POLICY (2026-11-06): the HR Manager and the Marketing Manager may PROPOSE a wording change, only the Super Admin may approve it,
 * and a proposal never replaces the wording in use before it is approved. Synthetic keys and wording only; the panel's approved messages are not touched.
 */
class TranslationProposalTest extends TestCase
{
    use RefreshDatabase;

    private Admin $super;

    private Admin $hr;

    private Admin $marketing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = Admin::factory()->superAdmin()->create();
        $this->hr = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $this->marketing = Admin::factory()->create(['role' => AdminRole::MarketingManager]);
    }

    private function as(Admin $admin): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($admin->createToken('t')->plainTextToken);
    }

    private function overrides(): array
    {
        return (array) $this->as($this->super)->getJson('/api/v1/admin/i18n/overrides')->assertOk()->json('data.overrides');
    }

    private function propose(Admin $who, array $body = [])
    {
        return $this->as($who)->postJson('/api/v1/admin/i18n/proposals', array_replace(['key' => 'action.uiAuditB.yesCancelRequest', 'so' => 'Keydi fiican', 'reason' => 'Clearer wording'], $body));
    }

    public function test_the_hr_manager_and_the_marketing_manager_can_propose_and_nothing_in_use_changes(): void
    {
        $this->assertSame([], $this->overrides());
        $auditBefore = AuditLog::query()->count();

        $hr = $this->propose($this->hr)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.proposed_by.role', 'hr_manager')->assertJsonPath('data.so', 'Keydi fiican');
        $this->propose($this->marketing, ['key' => 'confirm.uiAuditB.cancelRequestTitle', 'en' => 'Stop'])->assertCreated()->assertJsonPath('data.proposed_by.role', 'marketing_manager');

        // The wording in use is exactly what it was: no override exists, only two pending proposals.
        $this->assertSame([], $this->overrides());
        $this->assertSame(0, SystemSetting::query()->where('category', 'i18n')->count());
        $this->assertSame(2, SystemSetting::query()->where('category', 'i18n_proposal')->count());
        $this->assertSame($auditBefore + 2, AuditLog::query()->count());
        $this->assertDatabaseHas('audit_logs', ['entity_type' => SystemSetting::class, 'entity_id' => $hr->json('data.id'), 'action' => 'create']);
    }

    public function test_nobody_else_can_propose_and_the_super_admin_edits_directly(): void
    {
        $employee = Admin::factory()->create(['role' => AdminRole::MarketingEmployee]);
        $before = SystemSetting::query()->count();

        $this->propose($employee)->assertStatus(403)->assertJsonPath('error_code', 'TRANSLATION_PROPOSAL_NOT_ALLOWED');
        $this->propose($this->super)->assertStatus(403)->assertJsonPath('error_code', 'TRANSLATION_PROPOSAL_NOT_ALLOWED');
        $this->as($employee)->getJson('/api/v1/admin/i18n/proposals')->assertStatus(403);

        $this->assertSame($before, SystemSetting::query()->count());
    }

    public function test_validation_needs_a_key_and_some_wording_and_refuses_control_characters(): void
    {
        $this->propose($this->hr, ['key' => '', 'so' => 'x'])->assertStatus(422);
        $this->propose($this->hr, ['so' => '   ', 'en' => null])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->propose($this->hr, ['so' => "bad\x07text"])->assertStatus(422);
        $this->propose($this->hr, ['so' => str_repeat('x', 1001)])->assertStatus(422);
        $this->assertSame(0, SystemSetting::query()->where('category', 'i18n_proposal')->count());
    }

    public function test_the_hr_manager_cannot_approve_reject_or_edit_the_wording_directly(): void
    {
        $id = $this->propose($this->hr)->assertCreated()->json('data.id');

        $this->as($this->hr)->postJson("/api/v1/admin/i18n/proposals/{$id}/approve")->assertStatus(403);
        $this->as($this->marketing)->postJson("/api/v1/admin/i18n/proposals/{$id}/approve")->assertStatus(403);
        $this->as($this->hr)->postJson("/api/v1/admin/i18n/proposals/{$id}/reject", ['note' => 'no'])->assertStatus(403);
        $this->as($this->hr)->putJson('/api/v1/admin/i18n/overrides/action.uiAuditB.yesCancelRequest', ['so' => 'Direct edit'])->assertStatus(403);

        $this->assertSame([], $this->overrides());
        $this->assertSame('pending', SystemSetting::query()->findOrFail($id)->value['status']);
    }

    public function test_the_super_admin_approves_and_only_then_the_wording_in_use_changes(): void
    {
        $id = $this->propose($this->hr, ['en' => 'Keep', 'so' => 'Keydi fiican'])->assertCreated()->json('data.id');
        $this->assertSame([], $this->overrides());

        $this->as($this->super)->postJson("/api/v1/admin/i18n/proposals/{$id}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by.id', $this->super->id);

        $this->assertSame(['action.uiAuditB.yesCancelRequest' => ['en' => 'Keep', 'so' => 'Keydi fiican']], $this->overrides());
        $row = SystemSetting::query()->findOrFail($id);
        $this->assertSame('approved', $row->value['status']);
        $this->assertSame($this->hr->id, $row->value['proposed_by'], 'who proposed it is kept');

        // The override write and the decision are both audited; the override entry says it came from a proposal.
        $override = AuditLog::query()->where('metadata->event', 'translation_proposal_approved')->firstOrFail();
        $this->assertSame($id, $override->metadata['proposal_id']);
        $this->assertSame(['en' => 'Keep', 'so' => 'Keydi fiican'], $override->metadata['after']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'admin_id' => $this->super->id, 'action' => 'update']);

        // Decided once: approving it again changes nothing.
        $this->as($this->super)->postJson("/api/v1/admin/i18n/proposals/{$id}/approve")->assertStatus(422)->assertJsonPath('error_code', 'PROPOSAL_NOT_PENDING');
        $this->as($this->super)->postJson("/api/v1/admin/i18n/proposals/{$id}/reject")->assertStatus(422)->assertJsonPath('error_code', 'PROPOSAL_NOT_PENDING');
    }

    public function test_a_rejected_proposal_changes_nothing_and_is_kept_with_the_reason(): void
    {
        $id = $this->propose($this->marketing)->assertCreated()->json('data.id');

        $this->as($this->super)->postJson("/api/v1/admin/i18n/proposals/{$id}/reject", ['note' => 'Keep the owner-approved wording'])->assertOk()
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_note', 'Keep the owner-approved wording');

        $this->assertSame([], $this->overrides());
        $this->assertSame('rejected', SystemSetting::query()->findOrFail($id)->value['status']);
    }

    public function test_one_pending_proposal_per_person_and_message_and_the_proposer_can_withdraw_it(): void
    {
        $id = $this->propose($this->hr)->assertCreated()->json('data.id');
        $this->propose($this->hr, ['so' => 'Wording two'])->assertStatus(422)->assertJsonPath('error_code', 'PROPOSAL_ALREADY_PENDING');
        $this->propose($this->marketing, ['so' => 'Someone else may propose the same message'])->assertCreated();

        $this->as($this->marketing)->postJson("/api/v1/admin/i18n/proposals/{$id}/withdraw")->assertStatus(404); // not theirs
        $this->as($this->hr)->postJson("/api/v1/admin/i18n/proposals/{$id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->propose($this->hr, ['so' => 'Wording two'])->assertCreated();

        $this->assertSame([], $this->overrides());
        $this->as($this->super)->postJson("/api/v1/admin/i18n/proposals/{$id}/approve")->assertStatus(422)->assertJsonPath('error_code', 'PROPOSAL_NOT_PENDING');
    }

    public function test_a_proposer_sees_only_their_own_proposals_and_the_super_admin_sees_all(): void
    {
        $this->propose($this->hr)->assertCreated();
        $this->propose($this->marketing, ['key' => 'confirm.uiAuditB.cancelRequestTitle'])->assertCreated();

        $this->assertSame(['action.uiAuditB.yesCancelRequest'], collect($this->as($this->hr)->getJson('/api/v1/admin/i18n/proposals')->assertOk()->json('data.proposals'))->pluck('message_key')->all());
        $this->assertSame(['confirm.uiAuditB.cancelRequestTitle'], collect($this->as($this->marketing)->getJson('/api/v1/admin/i18n/proposals')->assertOk()->json('data.proposals'))->pluck('message_key')->all());
        $this->assertEqualsCanonicalizing(['action.uiAuditB.yesCancelRequest', 'confirm.uiAuditB.cancelRequestTitle'], collect($this->as($this->super)->getJson('/api/v1/admin/i18n/proposals')->assertOk()->json('data.proposals'))->pluck('message_key')->all());

        $firstId = collect($this->as($this->super)->getJson('/api/v1/admin/i18n/proposals')->json('data.proposals'))->firstWhere('message_key', 'action.uiAuditB.yesCancelRequest')['id'];
        $this->as($this->super)->postJson("/api/v1/admin/i18n/proposals/{$firstId}/reject")->assertOk();
        $this->assertCount(2, $this->as($this->super)->getJson('/api/v1/admin/i18n/proposals')->json('data.proposals'), 'decided proposals stay listed');
        $this->assertSame(['confirm.uiAuditB.cancelRequestTitle'], collect($this->as($this->super)->getJson('/api/v1/admin/i18n/proposals?status=pending')->json('data.proposals'))->pluck('message_key')->all());
    }

    public function test_the_super_admin_editor_still_works_and_still_audits_exactly_as_before(): void
    {
        $this->as($this->super)->putJson('/api/v1/admin/i18n/overrides/action.uiAuditB.yesCancelRequest', ['so' => 'Direct by Super Admin'])->assertOk()->assertJsonPath('data.override.so', 'Direct by Super Admin');
        $this->assertSame(['action.uiAuditB.yesCancelRequest' => ['so' => 'Direct by Super Admin']], $this->overrides());
        $log = AuditLog::query()->where('description', 'Translation wording edited for "action.uiAuditB.yesCancelRequest".')->firstOrFail();
        $this->assertSame(['key' => 'action.uiAuditB.yesCancelRequest', 'before' => null, 'after' => ['so' => 'Direct by Super Admin']], $log->metadata);
    }

    // ------------------------------------------------------------------------------------------------ owner-locked wording (the 161 + the 86 + the two named approved additions)

    /** @return list<string> */
    private function lockedKeys(): array
    {
        return json_decode((string) file_get_contents(resource_path('i18n/locked-translation-keys.json')), true, 512, JSON_THROW_ON_ERROR)['keys'];
    }

    public function test_the_locked_list_has_249_keys_and_covers_both_pinned_baselines(): void
    {
        $locked = $this->lockedKeys();
        $this->assertCount(249, $locked);
        $this->assertSame($locked, array_values(array_unique($locked)), 'no duplicates');
        $this->assertNotContains('action.uiAuditB.yesCancelRequest', $locked, 'the proposed messages the tests use are not locked');
        $this->assertNotContains('confirm.uiAuditB.cancelRequestTitle', $locked);

        // The pinned baselines live in the Web Panel; when the whole repository is present, every key of the 161 and of the 86 must be locked here.
        $baselines = ['approved-161.json' => 161, 'approved-r1c-86.json' => 86];
        $found = 0;
        foreach ($baselines as $file => $count) {
            $path = base_path('../web-panel/src/i18n/baseline/'.$file);
            if (! is_file($path)) {
                continue;
            }
            $found++;
            $keys = array_column(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['messages'], 'key');
            $this->assertCount($count, $keys);
            $this->assertSame([], array_values(array_diff($keys, $locked)), "{$file}: every pinned message is locked on the server");
        }
        $this->assertTrue($found === 0 || $found === 2);
    }

    public function test_not_even_the_super_admin_editor_can_override_a_locked_message_and_the_active_wording_is_unchanged(): void
    {
        $setting = SystemSetting::query()->count();
        $audit = AuditLog::query()->count();

        // Every one of the 249 locked keys is refused, the first and the last included; nothing is stored or audited by any refusal.
        foreach ($this->lockedKeys() as $key) {
            $this->as($this->super)->putJson("/api/v1/admin/i18n/overrides/{$key}", ['en' => 'Replaced', 'so' => 'La beddelay'])
                ->assertStatus(422)->assertJsonPath('error_code', 'TRANSLATION_LOCKED');
        }

        $this->assertSame($setting, SystemSetting::query()->count());
        $this->assertSame($audit, AuditLog::query()->count());
        $this->assertSame([], $this->overrides(), 'the active wording is exactly what it was');

        // An ordinary (proposed) message can still be edited by the Super Admin.
        $this->as($this->super)->putJson('/api/v1/admin/i18n/overrides/action.uiAuditB.yesCancelRequest', ['so' => 'Wording of a proposed message'])->assertOk();
    }

    public function test_a_locked_message_cannot_be_proposed_and_a_stray_proposal_for_one_cannot_be_approved(): void
    {
        $locked = $this->lockedKeys()[0];
        $before = SystemSetting::query()->count();

        // Proposing is refused for the HR Manager and the Marketing Manager alike, with nothing stored.
        $this->propose($this->hr, ['key' => $locked])->assertStatus(422)->assertJsonPath('error_code', 'TRANSLATION_LOCKED');
        $this->propose($this->marketing, ['key' => $locked])->assertStatus(422)->assertJsonPath('error_code', 'TRANSLATION_LOCKED');
        $this->assertSame($before, SystemSetting::query()->count());

        // A pending proposal that somehow targets a locked key (written directly, as older data could be) can never be approved: the Super Admin's approval
        // authority does not reach the locked wording. It stays pending, nothing is written, and it can still be rejected.
        $stray = SystemSetting::query()->create(['category' => 'i18n_proposal', 'key' => 'p-stray', 'is_sensitive' => false, 'updated_by' => $this->hr->id, 'value' => [
            'message_key' => $locked, 'so' => 'Qoraal la soo jeediyey', 'status' => 'pending', 'proposed_by' => $this->hr->id, 'proposed_by_name' => 'x', 'proposed_by_role' => 'hr_manager', 'proposed_at' => now()->toIso8601String(),
        ]]);
        $audit = AuditLog::query()->count();

        $this->as($this->super)->postJson("/api/v1/admin/i18n/proposals/{$stray->id}/approve")->assertStatus(422)->assertJsonPath('error_code', 'TRANSLATION_LOCKED');

        $this->assertSame('pending', $stray->fresh()->value['status']);
        $this->assertSame(0, SystemSetting::query()->where('category', 'i18n')->count(), 'no override was written');
        $this->assertSame($audit, AuditLog::query()->count());
        $this->as($this->super)->postJson("/api/v1/admin/i18n/proposals/{$stray->id}/reject", ['note' => 'Locked wording'])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_an_override_row_stored_for_a_locked_message_is_never_handed_out(): void
    {
        $locked = $this->lockedKeys()[0];
        SystemSetting::query()->create(['category' => 'i18n', 'key' => $locked, 'is_sensitive' => false, 'value' => ['so' => 'Stray row']]);
        SystemSetting::query()->create(['category' => 'i18n', 'key' => 'action.uiAuditB.yesCancelRequest', 'is_sensitive' => false, 'value' => ['so' => 'Ordinary edit']]);

        $this->assertSame(['action.uiAuditB.yesCancelRequest' => ['so' => 'Ordinary edit']], $this->overrides());
        // Resetting (removing) an override stays available for every message.
        $this->as($this->super)->deleteJson("/api/v1/admin/i18n/overrides/{$locked}")->assertOk();
        $this->assertSame(0, SystemSetting::query()->where('category', 'i18n')->where('key', $locked)->count());
    }

    public function test_an_unprivileged_role_cannot_approve_or_override_whatever_the_key(): void
    {
        $id = $this->propose($this->hr)->assertCreated()->json('data.id');
        $employee = Admin::factory()->create(['role' => AdminRole::MarketingEmployee]);

        foreach ([$this->hr, $this->marketing, $employee] as $who) {
            $this->as($who)->postJson("/api/v1/admin/i18n/proposals/{$id}/approve")->assertStatus(403);
            $this->as($who)->putJson('/api/v1/admin/i18n/overrides/action.uiAuditB.yesCancelRequest', ['so' => 'No'])->assertStatus(403);
        }

        $this->assertSame([], $this->overrides());
        $this->assertSame('pending', SystemSetting::query()->findOrFail($id)->value['status']);
    }
}
