<?php

namespace Tests\Feature\Customer;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\Customer\CustomerStatus;
use App\Enums\UserStatus;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Permission;
use App\Models\User;
use App\Support\Customer\CustomerCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_code_generator_produces_sequential_cus_format(): void
    {
        CustomerProfile::factory()->create(['customer_number' => 'CUS-000001']);
        CustomerProfile::factory()->create(['customer_number' => 'CUS-000002']);

        $next = app(CustomerCodeGenerator::class)->next();

        $this->assertSame('CUS-000003', $next);
    }

    public function test_admin_can_create_and_list_customers(): void
    {
        $token = $this->tokenWithPermissions([
            AdminPermission::CustomersView,
            AdminPermission::CustomersCreate,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/admin/customers', [
                'full_name' => 'Hodan Abdi',
                'phone' => '+252611234567',
                'email' => 'hodan@example.com',
                'password' => 'Password1!',
            ])
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'Hodan Abdi')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.customer_number', 'CUS-000001');

        $this->withToken($token)
            ->getJson('/api/v1/admin/customers')
            ->assertOk()
            ->assertJsonPath('data.0.customer_number', 'CUS-000001');

        $user = User::query()->where('phone', '+252611234567')->firstOrFail();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertDatabaseHas('customer_activity_logs', [
            'customer_profile_id' => $user->customerProfile->id,
            'event_type' => 'registration',
        ]);
    }

    public function test_status_endpoint_updates_only_customer_profiles_status(): void
    {
        $profile = CustomerProfile::factory()->create([
            'status' => CustomerStatus::Active,
        ]);
        $profile->user->forceFill([
            'phone' => '+252611111111',
            'status' => UserStatus::Active,
        ])->save();

        $token = $this->tokenWithPermissions([AdminPermission::CustomersUpdate]);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/customers/{$profile->id}/status", [
                'status' => 'BLOCKED',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'BLOCKED');

        $profile->refresh();
        $profile->user->refresh();

        $this->assertSame(CustomerStatus::Blocked, $profile->status);
        $this->assertSame(UserStatus::Active, $profile->user->status);
    }

    public function test_soft_delete_and_super_admin_restore(): void
    {
        $profile = CustomerProfile::factory()->create();
        $super = Admin::factory()->superAdmin()->create();
        $token = $super->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/customers/{$profile->id}")
            ->assertOk();

        $this->assertSoftDeleted('customer_profiles', ['id' => $profile->id]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/customers/{$profile->id}/restore", [
                'status' => 'INACTIVE',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE');

        $this->assertDatabaseHas('customer_profiles', [
            'id' => $profile->id,
            'status' => 'INACTIVE',
            'deleted_at' => null,
        ]);
    }

    public function test_non_super_admin_cannot_restore(): void
    {
        $profile = CustomerProfile::factory()->create();
        $profile->delete();

        $token = $this->tokenWithPermissions([AdminPermission::CustomersRestore], AdminRole::Manager);

        $this->withToken($token)
            ->postJson("/api/v1/admin/customers/{$profile->id}/restore", [
                'status' => 'ACTIVE',
            ])
            ->assertForbidden();
    }

    public function test_addresses_notes_attachments_and_timeline(): void
    {
        Storage::fake('local');

        $profile = CustomerProfile::factory()->create();
        $token = $this->tokenWithPermissions([
            AdminPermission::CustomersView,
            AdminPermission::CustomersUpdate,
            AdminPermission::CustomersNotes,
            AdminPermission::CustomersAttachments,
        ]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/customers/{$profile->id}/addresses", [
                'address' => 'Hodan District Street 1',
                'city' => 'Mogadishu',
                'country' => 'SO',
                'district' => 'Hodan',
                'latitude' => 2.0469,
                'longitude' => 45.3182,
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.district', 'Hodan');

        $this->withToken($token)
            ->postJson("/api/v1/admin/customers/{$profile->id}/notes", [
                'note' => 'VIP corporate client',
            ])
            ->assertCreated()
            ->assertJsonPath('data.note', 'VIP corporate client');

        $this->withToken($token)
            ->post("/api/v1/admin/customers/{$profile->id}/attachments", [
                'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.file_type', 'pdf');

        $this->withToken($token)
            ->getJson("/api/v1/admin/customers/{$profile->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'address_added');
    }

    public function test_blocked_customer_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'blocked@example.com',
            'password' => Hash::make('Password1!'),
            'status' => UserStatus::Active,
        ]);

        CustomerProfile::factory()->create([
            'user_id' => $user->id,
            'status' => CustomerStatus::Blocked,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'blocked@example.com',
            'password' => 'Password1!',
        ])->assertUnauthorized();
    }

    public function test_permissions_are_enforced(): void
    {
        $token = $this->tokenWithPermissions([AdminPermission::CustomersView]);

        $this->withToken($token)
            ->postJson('/api/v1/admin/customers', [
                'full_name' => 'No Create',
                'phone' => '+252600000001',
                'password' => 'Password1!',
            ])
            ->assertForbidden();
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function tokenWithPermissions(array $permissions, AdminRole $role = AdminRole::Manager): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($permissions as $permission) {
            $permissionId = Permission::query()->where('key', $permission->value)->value('id');

            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $admin->createToken('admin-panel')->plainTextToken;
    }
}
