<?php

namespace Tests\Feature\Api\V1\Booking;

use App\Enums\BookingStatus;
use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelBookingTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 1;

    public function test_customer_can_cancel_an_owned_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking($profile, $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel", [
                'cancellation_reason' => 'Service is no longer needed.',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Booking cancelled successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Service is no longer needed.');

        self::assertNotNull($response->json('data.cancelled_at'));

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'Service is no longer needed.',
        ]);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'status' => 'cancelled',
            'changed_by_type' => 'user',
            'changed_by_id' => $user->id,
            'notes' => 'Service is no longer needed.',
        ]);
    }

    public function test_customer_cannot_cancel_an_already_cancelled_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking($profile, $service, $mode, BookingStatus::Cancelled);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'This booking cannot be cancelled.');
    }

    public function test_customer_can_cancel_an_owned_booking_without_a_reason(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking($profile, $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel");

        $response
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', null);
    }

    public function test_cancellation_rejects_an_oversized_reason(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking($profile, $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel", [
                'cancellation_reason' => str_repeat('a', 256),
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['cancellation_reason']]);
    }

    public function test_customer_cannot_cancel_a_completed_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking($profile, $service, $mode, BookingStatus::Completed);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel");

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'This booking cannot be cancelled.');
    }

    public function test_non_owned_booking_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $otherBooking = $this->createBooking(CustomerProfile::factory()->create(), $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/bookings/{$otherBooking->id}/cancel");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'BOOKING_NOT_FOUND');
    }

    public function test_cancellation_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking(CustomerProfile::factory()->create(), $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson("/api/v1/bookings/{$booking->id}/cancel");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_cancellation_requires_authentication(): void
    {
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking(CustomerProfile::factory()->create(), $service, $mode);

        $this
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
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

    private function createBooking(
        CustomerProfile $profile,
        Service $service,
        ServiceModeOption $mode,
        BookingStatus $status = BookingStatus::Submitted,
    ): Booking {
        return Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), $this->bookingSequence++),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => $status,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => [
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ],
            'customer_notes' => 'Please call before arrival.',
            'cancelled_at' => $status === BookingStatus::Cancelled ? now() : null,
        ]);
    }
}
