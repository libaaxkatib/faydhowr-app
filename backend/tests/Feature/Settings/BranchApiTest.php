<?php

namespace Tests\Feature\Settings;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\Settings\BranchStatus;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Permission;
use Database\Seeders\BranchSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BranchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/branches')->assertStatus(401);
    }

    public function test_admin_without_the_settings_permission_is_rejected(): void
    {
        $token = Admin::factory()->create(['role' => AdminRole::Sales])
            ->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/branches')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_branches_index_lists_the_seeded_branches(): void
    {
        $this
            ->withToken($this->viewToken())
            ->getJson('/api/v1/admin/branches')
            ->assertOk()
            ->assertJsonPath('message', 'Branches retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'MGQ')
            ->assertJsonPath('data.0.status', 'ACTIVE')
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.1.code', 'HGA')
            ->assertJsonPath('data.1.status', 'COMING_SOON')
            ->assertJsonPath('data.1.is_default', false);
    }

    public function test_branch_detail_returns_one_branch(): void
    {
        $mogadishu = Branch::query()->where('code', 'MGQ')->sole();

        $this
            ->withToken($this->viewToken())
            ->getJson('/api/v1/admin/branches/'.$mogadishu->id)
            ->assertOk()
            ->assertJsonPath('data.code', 'MGQ')
            ->assertJsonPath('data.city', 'Mogadishu');
    }

    public function test_only_a_super_admin_can_activate_a_branch(): void
    {
        $hargeisa = Branch::query()->where('code', 'HGA')->sole();

        $this
            ->withToken($this->manageToken())
            ->patchJson("/api/v1/admin/branches/{$hargeisa->id}/activate")
            ->assertStatus(403);

        $this->assertSame(BranchStatus::ComingSoon, $hargeisa->refresh()->status);
    }

    public function test_a_super_admin_can_activate_a_branch(): void
    {
        $hargeisa = Branch::query()->where('code', 'HGA')->sole();

        $this
            ->withToken($this->superAdminToken())
            ->patchJson("/api/v1/admin/branches/{$hargeisa->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertDatabaseHas('settings_audit_logs', [
            'category' => 'branch',
            'key' => 'status',
        ]);
    }

    public function test_a_coming_soon_branch_cannot_become_the_default(): void
    {
        $hargeisa = Branch::query()->where('code', 'HGA')->sole();

        $this
            ->withToken($this->superAdminToken())
            ->patchJson("/api/v1/admin/branches/{$hargeisa->id}/default")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BRANCH_NOT_ACTIVE');

        $this->assertTrue(Branch::query()->where('code', 'MGQ')->sole()->is_default);
    }

    public function test_only_a_super_admin_can_change_the_default_branch(): void
    {
        $mogadishu = Branch::query()->where('code', 'MGQ')->sole();

        $this
            ->withToken($this->manageToken())
            ->patchJson("/api/v1/admin/branches/{$mogadishu->id}/default")
            ->assertStatus(403);
    }

    public function test_an_activated_branch_can_become_the_default(): void
    {
        $hargeisa = Branch::query()->where('code', 'HGA')->sole();
        Branch::query()->whereKey($hargeisa->id)->update([
            'status' => BranchStatus::Active->value,
            'activated_at' => now(),
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->patchJson("/api/v1/admin/branches/{$hargeisa->id}/default")
            ->assertOk()
            ->assertJsonPath('data.code', 'HGA')
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse(Branch::query()->where('code', 'MGQ')->sole()->is_default);
        $this->assertSame(1, Branch::query()->where('is_default', true)->count());
    }

    private function superAdminToken(): string
    {
        return Admin::factory()->superAdmin()->create()
            ->createToken('admin-panel')->plainTextToken;
    }

    private function viewToken(): string
    {
        return $this->tokenWithPermission(AdminPermission::SettingsView);
    }

    private function manageToken(): string
    {
        return $this->tokenWithPermission(AdminPermission::SettingsManage);
    }

    private function tokenWithPermission(AdminPermission $permission): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin->createToken('admin-panel')->plainTextToken;
    }
}
