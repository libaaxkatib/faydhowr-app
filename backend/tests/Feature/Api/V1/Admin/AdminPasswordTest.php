<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_change_a_password(): void
    {
        $this->putJson('/api/v1/admin/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_admin_can_change_their_own_password(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password changed successfully.');

        $this->assertTrue(Hash::check('new-password-1', $admin->fresh()->password));
    }

    public function test_current_password_is_required_and_verified(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'totally-wrong-password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'CURRENT_PASSWORD_INCORRECT');

        // Password must not have changed.
        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'does-not-match',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertTrue(Hash::check('password', $admin->fresh()->password));
    }

    public function test_new_password_must_meet_minimum_length(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_password_is_hashed_and_never_returned_or_stored_plaintext(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ]);

        $response->assertOk();
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('password');

        $stored = DB::table('admins')->where('id', $admin->id)->value('password');
        $this->assertNotSame('new-password-1', $stored);
        $this->assertTrue(Hash::check('new-password-1', $stored));
    }

    public function test_changing_own_password_keeps_the_current_session_but_revokes_other_tokens(): void
    {
        $admin = Admin::factory()->create();
        $currentAccessToken = $admin->createToken('admin-panel');
        $currentToken = $currentAccessToken->plainTextToken;
        $otherDevice = $admin->createToken('admin-panel');

        $this
            ->withToken($currentToken)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertOk();

        // Verified at the database level: the row backing the current session's
        // token still exists, every other token for this admin is gone. (A live
        // second HTTP round-trip in the same test method isn't reliable here -
        // Sanctum's guard can cache the resolved user across calls that share one
        // PHP process/container, which never happens for real, separate requests
        // in production - so the DB is the trustworthy source of truth for this.)
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentAccessToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherDevice->accessToken->id]);
        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_login_works_with_the_new_password_after_change(): void
    {
        $admin = Admin::factory()->create(['email' => 'change-me@example.com']);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/auth/password', [
                'current_password' => 'password',
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertOk();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'change-me@example.com',
            'password' => 'new-password-1',
        ])->assertOk();
    }

    public function test_super_admin_can_reset_another_admins_password(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $targetOldAccessToken = $target->createToken('admin-panel');

        $this
            ->withToken($superAdmin->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/password", [
                'password' => 'reset-password-1',
                'password_confirmation' => 'reset-password-1',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password reset successfully.');

        $this->assertTrue(Hash::check('reset-password-1', $target->fresh()->password));

        // Every existing session for the target is revoked by a Super-Admin-initiated
        // reset (verified at the DB level - see the note in the self-change test above).
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $targetOldAccessToken->accessToken->id]);
        $this->assertSame(0, $target->tokens()->count());
    }

    /**
     * Each of these three is granted admins.manage DIRECTLY (the permission the
     * route middleware checks) so the test isolates the ACTION's own role check
     * — proving the 403 comes from `$actor->role !== AdminRole::SuperAdmin` in
     * ResetAdminPasswordAction, not merely from the route's permission gate.
     */
    public function test_hr_manager_cannot_reset_another_admins_password_even_with_admins_manage_granted(): void
    {
        $this->assertNonSuperAdminCannotResetPassword(AdminRole::HrManager);
    }

    public function test_marketing_manager_cannot_reset_another_admins_password_even_with_admins_manage_granted(): void
    {
        $this->assertNonSuperAdminCannotResetPassword(AdminRole::MarketingManager);
    }

    public function test_mobile_app_manager_cannot_reset_another_admins_password_even_with_admins_manage_granted(): void
    {
        $this->assertNonSuperAdminCannotResetPassword(AdminRole::MobileAppManager);
    }

    private function assertNonSuperAdminCannotResetPassword(AdminRole $actorRole): void
    {
        $actor = Admin::factory()->create(['role' => $actorRole]);
        $this->grantPermissions($actor, [AdminPermission::AdminsManage]);
        $target = Admin::factory()->create(['role' => AdminRole::HrManager]);

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/password", [
                'password' => 'reset-password-1',
                'password_confirmation' => 'reset-password-1',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN')
            ->assertJsonPath('message', 'Only Super Admin may reset another admin\'s password.');

        $this->assertTrue(Hash::check('password', $target->fresh()->password));
    }

    public function test_admin_without_admins_manage_permission_cannot_reset_a_password(): void
    {
        $hrManager = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $target = Admin::factory()->create(['role' => AdminRole::MarketingManager]);

        $this
            ->withToken($hrManager->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/password", [
                'password' => 'reset-password-1',
                'password_confirmation' => 'reset-password-1',
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_reset_password_endpoint_never_returns_a_plaintext_or_hashed_password(): void
    {
        $superAdmin = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create(['role' => AdminRole::MobileAppManager]);

        $response = $this
            ->withToken($superAdmin->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}/password", [
                'password' => 'reset-password-1',
                'password_confirmation' => 'reset-password-1',
            ]);

        $response->assertOk();
        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('password');
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function grantPermissions(Admin $admin, array $permissions): void
    {
        $now = now();

        DB::table('admin_permissions')->insert(
            collect($permissions)
                ->map(fn (AdminPermission $permission): array => [
                    'admin_id' => $admin->id,
                    'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );
    }
}
