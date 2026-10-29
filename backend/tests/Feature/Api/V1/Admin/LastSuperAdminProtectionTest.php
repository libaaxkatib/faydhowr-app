<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Support\LastSuperAdminGuard;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner decision 2026-10-29: the last active Super Admin can never be demoted, deactivated or deleted. Super Admin cannot be created from the
 * panel, so losing the last one would leave nobody able to manage accounts, roles or settings.
 *
 * The guard locks every active Super Admin (SELECT ... FOR UPDATE, in id order) before it counts. SQLite, used by the test suite, has no row locks
 * and runs one request at a time, so the race is tested through its consequence: after one of two simultaneous changes has been applied, the other
 * is re-evaluated against the committed state and refused. The lock itself is exercised on PostgreSQL by the release rehearsal.
 */
class LastSuperAdminProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(string $status = 'active'): Admin
    {
        return Admin::factory()->superAdmin()->create(['status' => $status]);
    }

    private function token(Admin $admin): string
    {
        return $admin->createToken('t')->plainTextToken;
    }

    private function update(Admin $actor, Admin $target, array $changes)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token($actor))->putJson("/api/v1/admin/admins/{$target->id}", $changes);
    }

    public function test_the_only_active_super_admin_cannot_be_demoted(): void
    {
        $only = $this->superAdmin();

        $this->update($only, $only, ['role' => AdminRole::HrManager->value])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'LAST_SUPER_ADMIN');

        $this->assertSame(AdminRole::SuperAdmin, $only->fresh()->role);
    }

    public function test_the_only_active_super_admin_cannot_be_deactivated(): void
    {
        $only = $this->superAdmin();

        $this->update($only, $only, ['status' => AdminStatus::Inactive->value])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'LAST_SUPER_ADMIN');

        $this->assertSame(AdminStatus::Active, $only->fresh()->status);
    }

    public function test_the_blocked_message_says_why_and_nothing_else_in_the_request_is_saved(): void
    {
        $only = $this->superAdmin();
        $name = $only->full_name;

        $this->update($only, $only, ['full_name' => 'Renamed Anyway', 'status' => AdminStatus::Inactive->value])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'At least one active Super Admin must remain'));

        $this->assertSame($name, $only->fresh()->full_name);
    }

    public function test_the_only_active_super_admin_cannot_delete_themselves(): void
    {
        $only = $this->superAdmin();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($only))->deleteJson("/api/v1/admin/admins/{$only->id}")->assertStatus(422);

        $this->assertNotNull(Admin::query()->find($only->id));
    }

    public function test_an_inactive_super_admin_does_not_count_as_a_remaining_one(): void
    {
        $active = $this->superAdmin();
        $this->superAdmin('inactive');

        $this->update($active, $active, ['role' => AdminRole::HrManager->value])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'LAST_SUPER_ADMIN');
    }

    public function test_a_super_admin_can_be_demoted_deactivated_or_deleted_while_another_active_one_remains(): void
    {
        $first = $this->superAdmin();
        $second = $this->superAdmin();
        $third = $this->superAdmin();

        $this->update($first, $second, ['status' => AdminStatus::Inactive->value])->assertOk();
        $this->update($first, $third, ['role' => AdminRole::MarketingManager->value])->assertOk();

        $this->assertSame(AdminStatus::Inactive, $second->fresh()->status);
        $this->assertSame(AdminRole::MarketingManager, $third->fresh()->role);

        // Reactivate the second, then the first may step down: one active Super Admin still remains.
        $this->update($first, $second, ['status' => AdminStatus::Active->value])->assertOk();
        $this->update($first, $first, ['role' => AdminRole::HrManager->value])->assertOk();

        // The remaining Super Admin is now the last one and is protected.
        $this->update($second, $second, ['status' => AdminStatus::Inactive->value])->assertStatus(422)->assertJsonPath('error_code', 'LAST_SUPER_ADMIN');

        $deletable = $this->superAdmin();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($second))->deleteJson("/api/v1/admin/admins/{$deletable->id}")->assertOk();
    }

    public function test_changes_that_keep_the_only_super_admin_active_are_allowed(): void
    {
        $only = $this->superAdmin();

        $this->update($only, $only, ['full_name' => 'Owner Account', 'role' => AdminRole::SuperAdmin->value, 'status' => AdminStatus::Active->value])->assertOk();

        $this->assertSame('Owner Account', $only->fresh()->full_name);
    }

    public function test_other_roles_are_never_affected_by_the_guard(): void
    {
        $only = $this->superAdmin();
        $hr = Admin::factory()->create(['role' => AdminRole::HrManager]);

        $this->update($only, $hr, ['status' => AdminStatus::Inactive->value])->assertOk();
        $this->update($only, $hr, ['role' => AdminRole::MarketingManager->value])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($only))->deleteJson("/api/v1/admin/admins/{$hr->id}")->assertOk();
    }

    public function test_two_simultaneous_changes_cannot_remove_both_super_admins(): void
    {
        $a = $this->superAdmin();
        $b = $this->superAdmin();

        // Each request runs the guard inside its own transaction. Whichever commits first wins; the other re-reads the committed rows and is refused.
        DB::transaction(function () use ($b): void {
            LastSuperAdminGuard::assertOneRemains($b->id, AdminRole::HrManager, null);
            $b->forceFill(['role' => AdminRole::HrManager])->save();
        });

        try {
            DB::transaction(function () use ($a): void {
                LastSuperAdminGuard::assertOneRemains($a->id, null, AdminStatus::Inactive);
                $a->forceFill(['status' => AdminStatus::Inactive])->save();
            });
            $this->fail('The second change should have been refused.');
        } catch (DomainException $e) {
            $this->assertSame(LastSuperAdminGuard::CODE, $e->getMessage());
        }

        $this->assertSame(1, Admin::query()->where('role', AdminRole::SuperAdmin->value)->where('status', 'active')->count());
    }

    public function test_only_a_super_admin_can_manage_admins_and_super_admin_stays_unassignable(): void
    {
        $hr = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $super = $this->superAdmin();

        $this->update($hr, $super, ['status' => AdminStatus::Inactive->value])->assertForbidden();
        $this->update($super, $hr, ['role' => AdminRole::SuperAdmin->value])->assertUnprocessable();
    }
}
