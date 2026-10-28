<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): Admin
    {
        return Admin::factory()->superAdmin()->create();
    }

    private function asAdmin(Admin $admin): static
    {
        return $this->withToken($admin->createToken('admin-panel')->plainTextToken);
    }

    public function test_super_admin_saves_wording_and_every_signed_in_admin_reads_it_back(): void
    {
        $super = $this->superAdmin();

        $this->asAdmin($super)
            ->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => '  Maamulka Shaqaalaha  ', 'en' => 'Human Resources'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.override.so', 'Maamulka Shaqaalaha')
            ->assertJsonPath('data.override.en', 'Human Resources');

        $reader = Admin::factory()->create(['role' => AdminRole::HrEmployee]);

        $overrides = $this->asAdmin($reader)->getJson('/api/v1/admin/i18n/overrides')->assertOk()->json('data.overrides');

        $this->assertSame('Maamulka Shaqaalaha', $overrides['nav.hrGroup']['so']);
    }

    public function test_saving_again_replaces_the_wording_and_a_blank_language_keeps_the_built_in_text(): void
    {
        $super = $this->superAdmin();

        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/action.save', ['so' => 'Kaydi', 'en' => 'Store'])->assertOk();
        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/action.save', ['so' => 'Keydi', 'en' => ''])->assertOk()
            ->assertJsonPath('data.override.so', 'Keydi')
            ->assertJsonMissingPath('data.override.en');

        $this->assertSame(1, SystemSetting::query()->category('i18n')->count());
        $this->assertSame(['so' => 'Keydi'], SystemSetting::query()->category('i18n')->where('key', 'action.save')->firstOrFail()->value);
    }

    public function test_only_super_admin_can_edit_or_reset_and_nothing_is_stored_for_anyone_else(): void
    {
        foreach ([AdminRole::Manager, AdminRole::HrManager, AdminRole::HrEmployee, AdminRole::Accountant] as $role) {
            $admin = Admin::factory()->create(['role' => $role]);

            $this->asAdmin($admin)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => 'Waxbaa'])->assertForbidden();
            $this->asAdmin($admin)->deleteJson('/api/v1/admin/i18n/overrides/nav.hrGroup')->assertForbidden();
        }

        $this->assertSame(0, SystemSetting::query()->category('i18n')->count());
    }

    public function test_signed_out_visitors_cannot_read_or_change_wording(): void
    {
        $this->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => 'Waxbaa'])->assertUnauthorized();
        $this->deleteJson('/api/v1/admin/i18n/overrides/nav.hrGroup')->assertUnauthorized();
        $this->getJson('/api/v1/admin/i18n/overrides')->assertUnauthorized();
        $this->assertSame(0, SystemSetting::query()->category('i18n')->count());
    }

    public function test_validation_rejects_empty_oversized_control_character_and_malformed_keys(): void
    {
        $super = $this->superAdmin();

        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', [])->assertStatus(422);
        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => '   ', 'en' => ''])->assertStatus(422);
        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => str_repeat('a', 1001)])->assertStatus(422);
        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => "bad\x07text"])->assertStatus(422);
        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/'.str_repeat('k', 101), ['so' => 'x'])->assertStatus(422);
        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/bad%20key', ['so' => 'x'])->assertStatus(422);
        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => ['not', 'text']])->assertStatus(422);

        $this->assertSame(0, SystemSetting::query()->category('i18n')->count());
    }

    public function test_every_edit_and_reset_leaves_an_audit_record_with_before_and_after(): void
    {
        $super = $this->superAdmin();

        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => 'Hore'])->assertOk();
        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => 'Dambe'])->assertOk();
        $this->asAdmin($super)->deleteJson('/api/v1/admin/i18n/overrides/nav.hrGroup')->assertOk();

        $logs = AuditLog::query()->where('entity_type', SystemSetting::class)->orderBy('id')->get();

        $this->assertCount(3, $logs);
        $this->assertSame(['create', 'update', 'delete'], $logs->pluck('action')->map(fn ($action) => $action instanceof \BackedEnum ? $action->value : $action)->all());
        $this->assertSame($super->id, $logs[0]->admin_id);
        $this->assertNull($logs[0]->metadata['before']);
        $this->assertSame(['so' => 'Hore'], $logs[1]->metadata['before']);
        $this->assertSame(['so' => 'Dambe'], $logs[1]->metadata['after']);
        $this->assertSame(['so' => 'Dambe'], $logs[2]->metadata['before']);
        $this->assertNull($logs[2]->metadata['after']);
    }

    public function test_reset_returns_the_message_to_the_built_in_wording_and_is_harmless_when_nothing_was_edited(): void
    {
        $super = $this->superAdmin();

        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => 'Hore'])->assertOk();
        $this->asAdmin($super)->deleteJson('/api/v1/admin/i18n/overrides/nav.hrGroup')->assertOk();
        $this->asAdmin($super)->deleteJson('/api/v1/admin/i18n/overrides/nav.hrGroup')->assertOk();

        $this->asAdmin($super)->getJson('/api/v1/admin/i18n/overrides')->assertOk()->assertJsonPath('data.overrides', []);
    }

    public function test_editing_wording_changes_no_other_data(): void
    {
        $super = $this->superAdmin();
        $others = SystemSetting::query()->where('category', '!=', 'i18n')->count();

        $this->asAdmin($super)->putJson('/api/v1/admin/i18n/overrides/nav.hrGroup', ['so' => 'Hore'])->assertOk();

        $this->assertSame($others, SystemSetting::query()->where('category', '!=', 'i18n')->count());
    }
}
