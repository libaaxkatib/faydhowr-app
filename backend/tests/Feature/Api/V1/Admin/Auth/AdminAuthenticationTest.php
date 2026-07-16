<?php

namespace Tests\Feature\Api\V1\Admin\Auth;

use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'status' => AdminStatus::Active,
            'last_login_at' => null,
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'ADMIN@EXAMPLE.COM',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.admin.id', $admin->id)
            ->assertJsonPath('data.admin.email', 'admin@example.com')
            ->assertJsonPath('data.admin.full_name', $admin->full_name)
            ->assertJsonPath('data.admin.role', $admin->role->value)
            ->assertJsonPath('data.admin.status', 'active')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonMissingPath('data.admin.password')
            ->assertJsonStructure([
                'data' => [
                    'admin' => ['id', 'full_name', 'email', 'phone', 'role', 'status', 'last_login_at'],
                    'access_token',
                    'token_type',
                ],
            ]);

        $this->assertNotNull($admin->fresh()->last_login_at);
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'admin-panel',
            'tokenable_type' => Admin::class,
            'tokenable_id' => $admin->id,
        ]);
    }

    public function test_admin_cannot_login_with_invalid_credentials(): void
    {
        Admin::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Invalid email or password.',
                'error_code' => 'INVALID_CREDENTIALS',
            ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'password123',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
    }

    public function test_inactive_admin_cannot_login(): void
    {
        Admin::factory()->inactive()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ])
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Admin account is inactive.',
                'error_code' => 'ADMIN_ACCOUNT_INACTIVE',
            ]);
    }

    public function test_authenticated_admin_can_retrieve_profile(): void
    {
        $admin = Admin::factory()->create([
            'email' => 'admin@example.com',
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Authenticated admin retrieved successfully.')
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.email', 'admin@example.com')
            ->assertJsonPath('data.full_name', $admin->full_name)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.access_token');
    }

    public function test_authenticated_admin_can_logout_and_revoke_current_token(): void
    {
        $admin = Admin::factory()->create();
        $currentToken = $admin->createToken('admin-panel');
        $otherToken = $admin->createToken('admin-panel');

        $this
            ->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/admin/auth/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout successful.');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);

        $this->app['auth']->forgetGuards();

        $this
            ->withToken($currentToken->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this
            ->withToken($otherToken->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id);
    }

    public function test_unauthenticated_access_to_admin_auth_endpoints_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/admin/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_customer_token_cannot_access_admin_auth_me(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/admin/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
