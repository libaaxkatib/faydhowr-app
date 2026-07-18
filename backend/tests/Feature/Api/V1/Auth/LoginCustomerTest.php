<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_login_with_case_insensitive_email_and_receive_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'customer@example.com',
            'password' => Hash::make('password123'),
        ]);

        CustomerProfile::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'CUSTOMER@EXAMPLE.COM',
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'customer@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('meta', null)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'access_token',
                    'token_type',
                ],
                'meta',
            ]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'customer-mobile',
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_customer_cannot_login_with_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'customer@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'incorrect-password',
        ]);

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Invalid email or password.',
                'error_code' => 'INVALID_CREDENTIALS',
            ]);
    }

    public function test_customer_cannot_login_with_an_unknown_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Invalid email or password.',
                'error_code' => 'INVALID_CREDENTIALS',
            ]);
    }

    public function test_customer_login_requires_valid_input(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_customer_cannot_login_when_email_login_is_disabled(): void
    {
        config()->set('auth_features.email_login', false);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'customer@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'Email login is currently unavailable.',
                'error_code' => 'AUTH_METHOD_DISABLED',
            ]);
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'password123',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'password123',
        ])->assertTooManyRequests();
    }
}
