<?php

namespace Tests\Feature\Api\V1\Customer;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAddressLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_set_an_active_address_as_default_and_replace_previous_default(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $previousDefault = $this->createAddress($profile, [
            'label' => 'Home',
            'is_default' => true,
        ]);
        $newDefault = $this->createAddress($profile, [
            'label' => 'Office',
            'is_default' => false,
        ]);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson("/api/v1/customer/addresses/{$newDefault->id}/default");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $newDefault->id)
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseHas('customer_addresses', [
            'id' => $previousDefault->id,
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'id' => $newDefault->id,
            'is_default' => true,
        ]);
    }

    public function test_inactive_address_cannot_become_default(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $this->createAddress($profile, ['is_default' => true]);
        $inactiveAddress = $this->createAddress($profile, [
            'is_active' => false,
            'is_default' => true,
        ]);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson("/api/v1/customer/addresses/{$inactiveAddress->id}/default");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'An inactive address cannot be set as default.');
    }

    public function test_customer_cannot_deactivate_the_last_active_address(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $address = $this->createAddress($profile, ['is_default' => true]);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson("/api/v1/customer/addresses/{$address->id}/inactive");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The last active address cannot be made inactive.');
    }

    public function test_customer_must_choose_another_default_before_deactivating_the_current_one(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $defaultAddress = $this->createAddress($profile, ['is_default' => true]);
        $this->createAddress($profile, ['is_default' => false]);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson("/api/v1/customer/addresses/{$defaultAddress->id}/inactive");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath(
                'message',
                'Set another active address as default before making this address inactive.',
            );
    }

    public function test_customer_can_reactivate_an_address_without_making_it_default(): void
    {
        $user = User::factory()->create();
        $profile = $this->createProfile($user);
        $this->createAddress($profile, ['is_default' => true]);
        $inactiveAddress = $this->createAddress($profile, [
            'is_active' => false,
            'is_default' => true,
        ]);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson("/api/v1/customer/addresses/{$inactiveAddress->id}/reactivate");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $inactiveAddress->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_default', false);
    }

    public function test_customer_cannot_operate_on_another_customers_address(): void
    {
        $user = User::factory()->create();
        $this->createProfile($user);
        $otherProfile = $this->createProfile(User::factory()->create());
        $otherAddress = $this->createAddress($otherProfile);
        $token = $user->createToken('customer-mobile');

        $response = $this
            ->withToken($token->plainTextToken)
            ->postJson("/api/v1/customer/addresses/{$otherAddress->id}/default");

        $response
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Customer address not found.',
                'error_code' => 'CUSTOMER_ADDRESS_NOT_FOUND',
            ]);
    }

    public function test_unauthenticated_customer_cannot_operate_on_addresses(): void
    {
        $response = $this->postJson('/api/v1/customer/addresses/1/default');

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
