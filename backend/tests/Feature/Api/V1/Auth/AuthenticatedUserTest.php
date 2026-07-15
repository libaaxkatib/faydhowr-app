<?php

namespace Tests\Feature\Api\V1\Auth;

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
