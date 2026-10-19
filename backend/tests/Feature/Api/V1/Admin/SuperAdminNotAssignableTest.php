<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Approved decision: Super Admin is never assignable through the UI or the API.
 * hr_employee / marketing_employee are not selectable in the panel (see web-panel system.test.ts);
 * the API still accepts the existing non-Super-Admin roles so Manager/Sales/Inventory/Accountant are untouched.
 */
class SuperAdminNotAssignableTest extends TestCase
{
    use RefreshDatabase;

    private function actorToken(): string
    {
        return Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
    }

    private function payload(string $role): array
    {
        return [
            'full_name' => 'Delegated Admin',
            'email' => 'delegated@example.com',
            'phone' => '+252610000777',
            'password' => 'password123',
            'role' => $role,
            'status' => AdminStatus::Active->value,
        ];
    }

    public function test_an_admin_cannot_be_created_as_super_admin(): void
    {
        $this->withToken($this->actorToken())
            ->postJson('/api/v1/admin/admins', $this->payload(AdminRole::SuperAdmin->value))
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertDatabaseMissing('admins', ['email' => 'delegated@example.com']);
    }

    public function test_an_admin_cannot_be_promoted_to_super_admin(): void
    {
        $target = Admin::factory()->create(['role' => AdminRole::HrManager]);

        $this->withToken($this->actorToken())
            ->putJson("/api/v1/admin/admins/{$target->id}", ['role' => AdminRole::SuperAdmin->value])
            ->assertUnprocessable();

        $this->assertSame(AdminRole::HrManager, $target->fresh()->role);
    }

    public function test_the_three_delegated_roles_remain_assignable(): void
    {
        foreach ([AdminRole::HrManager, AdminRole::MarketingManager, AdminRole::MobileAppManager] as $i => $role) {
            $payload = $this->payload($role->value);
            $payload['email'] = "delegated{$i}@example.com";
            $payload['phone'] = '+25261000080'.$i;

            $this->withToken($this->actorToken())->postJson('/api/v1/admin/admins', $payload)->assertCreated()->assertJsonPath('data.role', $role->value);
        }
    }

    public function test_an_existing_super_admin_can_still_be_edited_without_changing_the_role(): void
    {
        $other = Admin::factory()->superAdmin()->create();

        $this->withToken($this->actorToken())
            ->putJson("/api/v1/admin/admins/{$other->id}", ['full_name' => 'Renamed Owner', 'role' => AdminRole::SuperAdmin->value])
            ->assertOk();

        $this->assertSame('Renamed Owner', $other->fresh()->full_name);
    }
}
