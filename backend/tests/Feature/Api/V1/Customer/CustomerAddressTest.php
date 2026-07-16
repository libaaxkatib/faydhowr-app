<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_list_only_their_addresses(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $this->createAddress($profile, ['label' => 'Home', 'is_default' => true]);
        $this->createAddress($profile, ['label' => 'Office']);

        $otherProfile = $this->createProfile(User::factory()->create());
        $this->createAddress($otherProfile, ['label' => 'Other Home']);

        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->getJson('/api/v1/customer/addresses');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Customer addresses retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.label', 'Home')
            ->assertJsonPath('meta', null);
    }

    public function test_customer_can_create_a_first_default_address(): void
    {
        $user = User::factory()->create();
        $this->createProfile($user);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson('/api/v1/customer/addresses', [
                'label' => 'Home',
                'contact_name' => 'Fayadhowr Customer',
                'phone' => '+252610000000',
                'line1' => '123 Main Street',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Customer address created successfully.')
            ->assertJsonPath('data.label', 'Home')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.customer_profile_id');

        $this->assertDatabaseHas('customer_addresses', [
            'customer_profile_id' => $user->customerProfile->id,
            'line1' => '123 Main Street',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_customer_can_view_an_owned_address(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $address = $this->createAddress($profile, ['label' => 'Home']);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->getJson("/api/v1/customer/addresses/{$address->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $address->id)
            ->assertJsonPath('data.label', 'Home');
    }

    public function test_customer_can_update_an_owned_address_without_reassigning_it(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $address = $this->createAddress($profile, ['city' => 'Mogadishu']);
        $otherProfile = $this->createProfile(User::factory()->create());
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->patchJson("/api/v1/customer/addresses/{$address->id}", [
                'city' => 'Hargeisa',
                'customer_profile_id' => $otherProfile->id,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.city', 'Hargeisa');

        $this->assertDatabaseHas('customer_addresses', [
            'id' => $address->id,
            'customer_profile_id' => $profile->id,
            'city' => 'Hargeisa',
        ]);
    }

    public function test_customer_cannot_access_or_update_another_customers_address(): void
    {
        $user = User::factory()->create();
        $this->createProfile($user);
        $otherProfile = $this->createProfile(User::factory()->create());
        $otherAddress = $this->createAddress($otherProfile);
        $token = $user->createToken('customer-mobile');

        $this
            ->withToken($token->plainTextToken)
            ->getJson("/api/v1/customer/addresses/{$otherAddress->id}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_ADDRESS_NOT_FOUND');

        $this
            ->withToken($token->plainTextToken)
            ->patchJson("/api/v1/customer/addresses/{$otherAddress->id}", [
                'city' => 'Hargeisa',
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_ADDRESS_NOT_FOUND');
    }

    public function test_customer_address_creation_validates_input(): void
    {
        $user = User::factory()->create();
        $this->createProfile($user);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson('/api/v1/customer/addresses', [
                'country_code' => 'SOM',
                'latitude' => 100,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['line1', 'city', 'country_code', 'latitude']);
    }

    public function test_unauthenticated_customer_cannot_access_addresses(): void
    {
        $response = $this->getJson('/api/v1/customer/addresses');

        $response
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
                'error_code' => 'UNAUTHENTICATED',
            ]);
    }

    private function createProfile(User $user): CustomerProfile
    {
        $profile = new CustomerProfile([
            'full_name' => 'Fayadhowr Customer',
            'preferred_language' => 'so',
        ]);
        $profile->customer_number = sprintf('CUS-2026-%06d', $user->id);
        $profile->classification = 'lead';
        $user->customerProfile()->save($profile);

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAddress(CustomerProfile $profile, array $attributes = []): CustomerAddress
    {
        return $profile->addresses()->create([
            'line1' => '123 Main Street',
            'city' => 'Mogadishu',
            'is_default' => false,
            'is_active' => true,
            ...$attributes,
        ]);
    }
}
