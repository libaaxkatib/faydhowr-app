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

class BookingRetrievalTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 1;

    public function test_customer_can_list_only_their_bookings_newest_first(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $olderBooking = $this->createBooking($profile, $service, $mode, BookingStatus::Submitted, now()->subDay());
        $newerBooking = $this->createBooking($profile, $service, $mode, BookingStatus::Completed, now());

        $otherProfile = CustomerProfile::factory()->create();
        $this->createBooking($otherProfile, $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/bookings');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Bookings retrieved successfully.')
            ->assertJsonPath('meta', null)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.booking_number', $newerBooking->booking_number)
            ->assertJsonPath('data.items.1.booking_number', $olderBooking->booking_number);
    }

    public function test_customer_bookings_are_paginated(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();

        $this->createBooking($profile, $service, $mode);
        $this->createBooking($profile, $service, $mode);
        $this->createBooking($profile, $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/bookings?per_page=2&page=2');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 2);
    }

    public function test_customer_can_filter_bookings_by_status(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $this->createBooking($profile, $service, $mode, BookingStatus::Submitted);
        $completedBooking = $this->createBooking($profile, $service, $mode, BookingStatus::Completed);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/bookings?status=completed');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.booking_number', $completedBooking->booking_number)
            ->assertJsonPath('data.items.0.status', 'completed');
    }

    public function test_customer_can_filter_bookings_by_service(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$matchingService, $matchingMode] = $this->createServiceWithMode();
        [$otherService, $otherMode] = $this->createServiceWithMode();
        $matchingBooking = $this->createBooking($profile, $matchingService, $matchingMode);
        $this->createBooking($profile, $otherService, $otherMode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/bookings?service_id={$matchingService->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.booking_number', $matchingBooking->booking_number)
            ->assertJsonPath('data.items.0.service.name', $matchingService->name);
    }

    public function test_booking_list_rejects_an_invalid_status_filter(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/bookings?status=invalid');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_booking_list_rejects_a_non_integer_service_filter(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/bookings?service_id=invalid');

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['service_id']]);
    }

    public function test_customer_can_view_an_owned_booking(): void
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking($profile, $service, $mode, BookingStatus::Scheduled);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/bookings/{$booking->id}");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Booking retrieved successfully.')
            ->assertJsonPath('data.booking_number', $booking->booking_number)
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.service.name', $service->name)
            ->assertJsonPath('data.service_mode.mode', ServiceMode::OneTime->value)
            ->assertJsonPath('data.requested_date', $booking->requested_date->toDateString())
            ->assertJsonPath('data.scheduled_start_at', $booking->scheduled_start_at?->toISOString())
            ->assertJsonPath('data.address_snapshot.city', 'Mogadishu')
            ->assertJsonPath('data.customer_notes', 'Please call before arrival.')
            ->assertJsonPath('data.created_at', $booking->created_at->toISOString())
            ->assertJsonMissingPath('data.customer_profile_id')
            ->assertJsonMissingPath('data.changed_by_id');
    }

    public function test_other_customers_booking_returns_not_found(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);
        [$service, $mode] = $this->createServiceWithMode();
        $otherBooking = $this->createBooking(CustomerProfile::factory()->create(), $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/bookings/{$otherBooking->id}");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'BOOKING_NOT_FOUND');
    }

    public function test_booking_retrieval_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/bookings');

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_booking_detail_returns_not_found_when_customer_profile_is_missing(): void
    {
        $user = User::factory()->create();
        [$service, $mode] = $this->createServiceWithMode();
        $booking = $this->createBooking(CustomerProfile::factory()->create(), $service, $mode);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/bookings/{$booking->id}");

        $response
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_booking_retrieval_requires_authentication(): void
    {
        $this
            ->getJson('/api/v1/bookings')
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
            'starting_from_price' => 45,
            'currency' => 'USD',
            'duration_minutes' => 120,
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
        mixed $createdAt = null,
    ): Booking {
        $booking = Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), $this->bookingSequence++),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => $status,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'scheduled_start_at' => $status === BookingStatus::Scheduled ? now()->addWeek() : null,
            'scheduled_end_at' => $status === BookingStatus::Scheduled ? now()->addWeek()->addHours(2) : null,
            'address_snapshot' => [
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ],
            'customer_notes' => 'Please call before arrival.',
        ]);

        if ($createdAt !== null) {
            $booking->forceFill(['created_at' => $createdAt])->save();
        }

        return $booking->refresh();
    }
}
