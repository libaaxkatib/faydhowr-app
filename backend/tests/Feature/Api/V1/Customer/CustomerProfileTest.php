<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_customer_can_retrieve_their_profile(): void
    {
        $user = User::factory()->create();
        $profile = new CustomerProfile([
            'full_name' => 'Fayadhowr Customer',
            'preferred_language' => 'so',
            'notification_preferences' => ['push' => true],
        ]);
        $profile->customer_number = 'CUS-2026-000001';
        $profile->classification = 'lead';
        $user->customerProfile()->save($profile);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->getJson('/api/v1/customer/profile');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Customer profile retrieved successfully.')
            ->assertJsonPath('data.customer_number', 'CUS-2026-000001')
            ->assertJsonPath('data.full_name', 'Fayadhowr Customer')
            ->assertJsonPath('data.preferred_language', 'so')
            ->assertJsonPath('data.classification', 'lead')
            ->assertJsonPath('data.notification_preferences.push', true)
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
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_authenticated_customer_without_a_profile_receives_not_found(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->getJson('/api/v1/customer/profile');

        $response
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Customer profile not found.',
                'error_code' => 'CUSTOMER_PROFILE_NOT_FOUND',
            ]);
    }

    public function test_unauthenticated_customer_cannot_retrieve_a_profile(): void
    {
        $response = $this->getJson('/api/v1/customer/profile');

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
            ]);
    }
}
