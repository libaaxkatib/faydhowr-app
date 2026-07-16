<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_are_created_through_events_not_direct_writes(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        event(AuditEvent::record(
            action: AuditAction::Approve,
            admin: $admin,
            description: 'Purchase order approved.',
            entityType: 'purchase_order',
            entityId: 42,
            metadata: ['po_number' => 'PO-2026-000001'],
        ));

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => AuditAction::Approve->value,
            'entity_type' => 'purchase_order',
            'entity_id' => 42,
            'description' => 'Purchase order approved.',
        ]);

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_admin_login_creates_login_audit_log(): void
    {
        $admin = Admin::factory()->superAdmin()->create([
            'email' => 'auditor@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'auditor@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => AuditAction::Login->value,
            'entity_type' => Admin::class,
            'entity_id' => $admin->id,
            'description' => 'Admin logged in.',
        ]);
    }

    public function test_admin_logout_creates_logout_audit_log(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => AuditAction::Logout->value,
            'entity_type' => Admin::class,
            'entity_id' => $admin->id,
            'description' => 'Admin logged out.',
        ]);
    }

    public function test_admin_create_creates_create_audit_log(): void
    {
        $actor = Admin::factory()->superAdmin()->create();

        $response = $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/admins', [
                'full_name' => 'New Admin',
                'email' => 'new.admin@example.com',
                'phone' => '+252610000333',
                'password' => 'password123',
                'role' => AdminRole::Sales->value,
                'status' => AdminStatus::Active->value,
            ]);

        $response->assertCreated();

        $createdId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $actor->id,
            'action' => AuditAction::Create->value,
            'entity_type' => Admin::class,
            'entity_id' => $createdId,
            'description' => 'Admin account created.',
        ]);
    }

    public function test_admin_update_creates_update_audit_log(): void
    {
        $actor = Admin::factory()->superAdmin()->create();
        $target = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this
            ->withToken($actor->createToken('admin-panel')->plainTextToken)
            ->putJson("/api/v1/admin/admins/{$target->id}", [
                'full_name' => 'Renamed Admin',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $actor->id,
            'action' => AuditAction::Update->value,
            'entity_type' => Admin::class,
            'entity_id' => $target->id,
            'description' => 'Admin account updated.',
        ]);
    }

    public function test_only_admins_with_admins_manage_permission_can_list_audit_logs(): void
    {
        $allowed = Admin::factory()->superAdmin()->create();
        $denied = Admin::factory()->create([
            'role' => AdminRole::Sales,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Login,
            admin: $allowed,
            description: 'Seed audit.',
            entityType: Admin::class,
            entityId: $allowed->id,
        ));

        $this
            ->withToken($allowed->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Audit logs retrieved successfully.')
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.action', AuditAction::Login->value)
            ->assertJsonPath('data.items.0.admin.id', $allowed->id)
            ->assertJsonMissingPath('data.items.0.user_agent')
            ->assertJsonMissingPath('data.items.0.ip_address');

        $this->app['auth']->forgetGuards();

        $this
            ->withToken($denied->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_audit_logs_support_filters_and_newest_first_order(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $other = Admin::factory()->create();

        $older = AuditLog::query()->create([
            'admin_id' => $admin->id,
            'action' => AuditAction::Create,
            'entity_type' => Admin::class,
            'entity_id' => $other->id,
            'description' => 'Older create.',
            'created_at' => now()->subDay(),
        ]);

        $newer = AuditLog::query()->create([
            'admin_id' => $admin->id,
            'action' => AuditAction::Update,
            'entity_type' => Admin::class,
            'entity_id' => $other->id,
            'description' => 'Newer update.',
            'created_at' => now(),
        ]);

        AuditLog::query()->create([
            'admin_id' => $other->id,
            'action' => AuditAction::Login,
            'entity_type' => Admin::class,
            'entity_id' => $other->id,
            'description' => 'Other admin login.',
            'created_at' => now()->subHour(),
        ]);

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $ordered = $this
            ->withToken($token)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->json('data.items');

        $this->assertSame($newer->id, $ordered[0]['id']);
        $this->assertSame($older->id, $ordered[2]['id']);

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/audit-logs?action=update')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $newer->id);

        $this
            ->withToken($token)
            ->getJson("/api/v1/admin/audit-logs?admin_id={$other->id}")
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.admin.id', $other->id);
    }

    public function test_customer_token_cannot_access_audit_logs(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/admin/audit-logs')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_unauthenticated_access_to_audit_logs_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/audit-logs')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
