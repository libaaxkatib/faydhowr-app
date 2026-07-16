<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_create_a_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $address = $this->createAddress($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload($service, $mode, $address));

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Booking created successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonMissingPath('data.customer_profile_id')
            ->assertJsonMissingPath('data.service_id')
            ->assertJsonPath('data.service.name', $service->name)
            ->assertJsonPath('data.service_mode.mode', ServiceMode::OneTime->value)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.requested_time_window', '09:00-12:00')
            ->assertJsonPath('data.address_snapshot.source_address_id', $address->id)
            ->assertJsonPath('data.address_snapshot.contact_name', $address->contact_name)
            ->assertJsonPath('data.address_snapshot.latitude', $address->latitude);

        self::assertMatchesRegularExpression(
            '/^BK-'.now()->format('Y').'-\d{6}$/',
            $response->json('data.booking_number'),
        );

        $this->assertDatabaseHas('bookings', [
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('booking_status_histories', [
            'status' => 'submitted',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
        ]);
    }

    public function test_booking_creation_rejects_an_invalid_service(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [, $mode] = $this->createServiceWithMode();
        $address = $this->createAddress($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload(999999, $mode, $address));

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('errors.service_id.0', 'The selected service id is invalid.');
    }

    public function test_booking_creation_rejects_a_mode_from_another_service(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service] = $this->createServiceWithMode();
        [, $otherMode] = $this->createServiceWithMode();
        $address = $this->createAddress($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload($service, $otherMode, $address));

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The selected booking mode is unavailable for this service.');
    }

    public function test_booking_creation_rejects_an_inactive_service(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $service->update(['is_active' => false]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload($service, $mode, $this->createAddress($profile)));

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The selected service is unavailable.');
    }

    public function test_booking_creation_rejects_an_inactive_service_mode(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $mode->update(['is_active' => false]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload($service, $mode, $this->createAddress($profile)));

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The selected booking mode is unavailable for this service.');
    }

    public function test_booking_creation_rejects_an_inactive_address(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $address = $this->createAddress($profile);
        $address->update(['is_active' => false]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload($service, $mode, $address));

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_ADDRESS_NOT_FOUND');
    }

    public function test_booking_creation_rejects_oversized_customer_notes(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $payload = $this->payload($service, $mode, $this->createAddress($profile));
        $payload['customer_notes'] = str_repeat('a', 5001);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['customer_notes']]);
    }

    public function test_booking_creation_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();
        [$service, $mode] = $this->createServiceWithMode();
        $address = CustomerAddress::factory()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload($service, $mode, $address));

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_booking_creation_returns_validation_errors(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $address = $this->createAddress($profile);

        $payload = $this->payload($service, $mode, $address);
        unset($payload['requested_date']);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['requested_date']]);
    }

    public function test_booking_creation_requires_authentication(): void
    {
        [$service, $mode] = $this->createServiceWithMode();
        $address = CustomerAddress::factory()->create();

        $this
            ->postJson('/api/v1/bookings', $this->payload($service, $mode, $address))
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_booking_creation_rejects_an_address_owned_by_another_customer(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $otherAddress = CustomerAddress::factory()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload($service, $mode, $otherAddress));

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_ADDRESS_NOT_FOUND');
    }

    public function test_customer_address_updates_do_not_change_existing_booking_snapshots(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $address = $this->createAddress($profile);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/bookings', $this->payload($service, $mode, $address))
            ->assertCreated();

        $booking = Booking::query()
            ->where('booking_number', $response->json('data.booking_number'))
            ->firstOrFail();
        $address->update(['line1' => 'Updated delivery address']);

        self::assertSame('KM4 Road', $booking->refresh()->address_snapshot['line1']);
    }

    /**
     * @return array{Service, ServiceModeOption}
     */
    private function createServiceWithMode(): array
    {
        $category = ServiceCategory::query()->create([
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $service = Service::query()->create([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $mode = ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return [$service, $mode];
    }

    /**
     * @return CustomerAddress
     */
    private function createAddress(CustomerProfile $profile): CustomerAddress
    {
        return CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
            'contact_name' => 'Booking Contact',
            'phone' => '+252610000000',
            'line1' => 'KM4 Road',
            'city' => 'Mogadishu',
            'country_code' => 'SO',
            'latitude' => 2.0469,
            'longitude' => 45.3182,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Service|int $service,
        ServiceModeOption $mode,
        CustomerAddress $address,
    ): array
    {
        return [
            'service_id' => $service instanceof Service ? $service->id : $service,
            'service_mode_id' => $mode->id,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'customer_address_id' => $address->id,
            'customer_notes' => 'Please call before arrival.',
        ];
    }
}
