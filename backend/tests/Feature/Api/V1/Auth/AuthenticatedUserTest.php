<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_customer_can_retrieve_their_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Fayadhowr Customer',
            'email' => 'customer@example.com',
            'remember_token' => 'hidden-token',
        ]);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Authenticated user retrieved successfully.')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Fayadhowr Customer')
            ->assertJsonPath('data.email', 'customer@example.com')
            ->assertJsonPath('meta', null)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['id', 'name', 'email'],
                'meta',
            ])
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_an_admin_token_on_the_customer_profile_route_is_refused_with_403_not_an_internal_error(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'This endpoint is for customer accounts.',
                'error_code' => 'FORBIDDEN',
            ]);
        $this->assertStringNotContainsString('Exception', $response->getContent());
        $this->assertStringNotContainsString($admin->email, $response->getContent());
    }

    public function test_the_admin_profile_route_still_works_for_the_same_admin_token(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('data.id', $admin->id);
    }

    public function test_an_invalid_token_on_the_customer_profile_route_is_401(): void
    {
        $this->withToken('999|not-a-real-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_unauthenticated_customer_cannot_retrieve_a_profile(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
            ]);
    }
}
