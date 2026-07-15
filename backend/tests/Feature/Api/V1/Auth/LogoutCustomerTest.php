<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_logout_and_only_the_current_token_is_revoked(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('customer-mobile');
        $otherToken = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/auth/logout');

        $response
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Logout successful.',
                'data' => null,
                'meta' => null,
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);
    }

    public function test_other_customer_tokens_remain_valid_after_logout(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('customer-mobile');
        $otherToken = $user->createToken('customer-mobile');

        $this
            ->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this
            ->withToken($otherToken->plainTextToken)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $user->id);
    }

    public function test_unauthenticated_customer_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/auth/logout');

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
            ]);
    }
}
