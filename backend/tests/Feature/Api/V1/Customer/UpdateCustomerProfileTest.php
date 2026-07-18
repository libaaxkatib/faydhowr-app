<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateCustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_customer_can_update_their_profile(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->patchJson('/api/v1/customer/profile', [
                'full_name' => 'Updated Customer',
                'avatar_url' => 'https://example.com/avatar.png',
                'preferred_language' => 'en',
                'notification_preferences' => ['push' => false],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Customer profile updated successfully.')
            ->assertJsonPath('data.customer_number', 'CUS-000001')
            ->assertJsonPath('data.full_name', 'Updated Customer')
            ->assertJsonPath('data.avatar_url', 'https://example.com/avatar.png')
            ->assertJsonPath('data.preferred_language', 'en')
            ->assertJsonPath('data.notification_preferences.push', false)
            ->assertJsonPath('meta', null)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'customer_number',
                    'full_name',
                    'avatar_url',
                    'preferred_language',
                    'classification',
                    'notification_preferences',
                    'member_since',
                ],
                'meta',
            ])
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.user_id');

        $this->assertDatabaseHas('customer_profiles', [
            'id' => $profile->id,
            'full_name' => 'Updated Customer',
            'preferred_language' => 'en',
        ]);
    }

    public function test_customer_profile_update_validates_input(): void
    {
        $user = User::factory()->create();
        $this->createProfile($user);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->patchJson('/api/v1/customer/profile', [
                'preferred_language' => 'fr',
                'avatar_url' => 'not-a-url',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['preferred_language', 'avatar_url']);
    }

    public function test_customer_profile_update_validates_notification_preference_structure(): void
    {
        $user = User::factory()->create();
        $this->createProfile($user);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->patchJson('/api/v1/customer/profile', [
                'notification_preferences' => [
                    'push' => 'invalid',
                    'unsupported' => true,
                ],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors([
                'notification_preferences',
                'notification_preferences.push',
            ]);
    }

    public function test_authenticated_customer_without_a_profile_cannot_update_it(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->patchJson('/api/v1/customer/profile', [
                'full_name' => 'Updated Customer',
            ]);

        $response
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Customer profile not found.',
                'error_code' => 'CUSTOMER_PROFILE_NOT_FOUND',
            ]);
    }

    public function test_unauthenticated_customer_cannot_update_a_profile(): void
    {
        $response = $this->patchJson('/api/v1/customer/profile', [
            'full_name' => 'Updated Customer',
        ]);

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
            ]);
    }

    public function test_read_only_and_unknown_fields_are_ignored(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $otherUser = User::factory()->create();
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->patchJson('/api/v1/customer/profile', [
                'customer_number' => 'CUS-999999',
                'classification' => 'active_customer',
                'user_id' => $otherUser->id,
                'unexpected_field' => 'ignored',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.customer_number', 'CUS-000001')
            ->assertJsonPath('data.classification', 'lead');

        $this->assertDatabaseHas('customer_profiles', [
            'id' => $profile->id,
            'user_id' => $user->id,
            'customer_number' => 'CUS-000001',
            'classification' => 'lead',
        ]);
    }

    private function createProfile(User $user): CustomerProfile
    {
        $profile = new CustomerProfile([
            'full_name' => 'Fayadhowr Customer',
            'preferred_language' => 'so',
        ]);
        $profile->customer_number = 'CUS-000001';
        $profile->classification = 'lead';
        $user->customerProfile()->save($profile);

        return $profile;
    }
}
