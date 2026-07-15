<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Actions\Auth\RegisterCustomerAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class RegisterCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_and_receive_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Fayadhowr Customer',
            'email' => 'customer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Registration successful.')
            ->assertJsonPath('data.user.name', 'Fayadhowr Customer')
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

        $user = User::query()->where('email', 'customer@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'customer-mobile',
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_customer_cannot_register_with_an_existing_email(): void
    {
        User::factory()->create(['email' => 'customer@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Duplicate Customer',
            'email' => 'customer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_customer_receives_a_safe_error_response_when_registration_fails(): void
    {
        $this->mock(RegisterCustomerAction::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new RuntimeException('Unexpected database failure.'));
        });

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Fayadhowr Customer',
            'email' => 'customer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Registration failed.',
                'error_code' => 'REGISTRATION_FAILED',
            ]);
    }
}
