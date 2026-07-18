<?php

namespace Tests\Feature\Api\V1\Reviews;

use App\Enums\BookingStatus;
use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ServiceModeOption;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 1;

    public function test_review_endpoints_require_authentication(): void
    {
        $this->postJson('/api/v1/reviews', [])->assertUnauthorized();
        $this->getJson('/api/v1/reviews')->assertUnauthorized();
        $this->putJson('/api/v1/reviews/1', [])->assertUnauthorized();
        $this->deleteJson('/api/v1/reviews/1')->assertUnauthorized();
    }

    public function test_customer_can_submit_a_review_for_a_completed_booking(): void
    {
        [$token, $profile] = $this->createCustomer();
        $service = $this->createService();
        $booking = $this->createBooking($profile, $service, BookingStatus::Completed);

        $response = $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 5,
                'title' => 'Excellent work',
                'comment' => 'The team was punctual and thorough.',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.booking_number', $booking->booking_number)
            ->assertJsonPath('data.service.slug', $service->slug);

        $this->assertDatabaseHas('reviews', [
            'booking_id' => $booking->id,
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('customer_activity_logs', [
            'customer_profile_id' => $profile->id,
            'event_type' => 'review_submitted',
        ]);

        $service->refresh();
        $this->assertSame(0, $service->reviews_count);
        $this->assertNull($service->average_rating);
    }

    public function test_review_submission_rejects_non_completed_bookings(): void
    {
        [$token, $profile] = $this->createCustomer();
        $booking = $this->createBooking($profile, $this->createService(), BookingStatus::InProgress);

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $booking->id, 'rating' => 4])
            ->assertConflict()
            ->assertJsonPath('error_code', 'REVIEW_NOT_ELIGIBLE');

        $this->assertSame(0, Review::query()->count());
    }

    public function test_review_submission_rejects_unknown_and_foreign_bookings(): void
    {
        [$token] = $this->createCustomer();
        $foreignBooking = $this->createBooking(
            CustomerProfile::factory()->create(),
            $this->createService(),
            BookingStatus::Completed,
        );

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $foreignBooking->id, 'rating' => 4])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => 999999, 'rating' => 4])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_a_booking_can_only_be_reviewed_once(): void
    {
        [$token, $profile] = $this->createCustomer();
        $booking = $this->createBooking($profile, $this->createService(), BookingStatus::Completed);

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $booking->id, 'rating' => 5])
            ->assertCreated();

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $booking->id, 'rating' => 3])
            ->assertConflict()
            ->assertJsonPath('error_code', 'REVIEW_ALREADY_EXISTS');
    }

    public function test_multiple_completed_bookings_allow_multiple_reviews(): void
    {
        [$token, $profile] = $this->createCustomer();
        $service = $this->createService();
        $firstBooking = $this->createBooking($profile, $service, BookingStatus::Completed);
        $secondBooking = $this->createBooking($profile, $service, BookingStatus::Completed);

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $firstBooking->id, 'rating' => 5])
            ->assertCreated();

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $secondBooking->id, 'rating' => 4])
            ->assertCreated();

        $this->assertSame(2, Review::query()->where('customer_profile_id', $profile->id)->count());
    }

    public function test_review_submission_validates_rating_title_and_comment(): void
    {
        [$token, $profile] = $this->createCustomer();
        $booking = $this->createBooking($profile, $this->createService(), BookingStatus::Completed);

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $booking->id, 'rating' => 6])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['rating']]);

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 4,
                'comment' => 'Too short',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['comment']]);

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 4,
                'comment' => str_repeat('a', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['comment']]);

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', [
                'booking_id' => $booking->id,
                'rating' => 4,
                'title' => str_repeat('a', 151),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['title']]);
    }

    public function test_customer_lists_only_their_own_reviews_in_all_statuses(): void
    {
        [$token, $profile] = $this->createCustomer();
        Review::factory()->for($profile)->create();
        Review::factory()->for($profile)->published()->create();
        Review::factory()->for($profile)->hidden()->create();
        Review::factory()->create();

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/reviews');

        $response
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_customer_review_list_is_paginated(): void
    {
        [$token, $profile] = $this->createCustomer();
        Review::factory()->for($profile)->count(3)->create();

        $this
            ->withToken($token)
            ->getJson('/api/v1/reviews?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_customer_can_update_a_pending_review(): void
    {
        [$token, $profile] = $this->createCustomer();
        $review = Review::factory()->for($profile)->create(['rating' => 3]);

        $this
            ->withToken($token)
            ->putJson("/api/v1/reviews/{$review->id}", [
                'rating' => 5,
                'title' => 'Updated title',
                'comment' => 'Updated comment after reflection.',
            ])
            ->assertOk()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.title', 'Updated title')
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_published_and_hidden_reviews_cannot_be_updated(): void
    {
        [$token, $profile] = $this->createCustomer();
        $published = Review::factory()->for($profile)->published()->create();
        $hidden = Review::factory()->for($profile)->hidden()->create();

        foreach ([$published, $hidden] as $review) {
            $this
                ->withToken($token)
                ->putJson("/api/v1/reviews/{$review->id}", ['rating' => 1])
                ->assertConflict()
                ->assertJsonPath('error_code', 'REVIEW_LOCKED');
        }
    }

    public function test_customer_can_delete_a_pending_review_and_resubmit(): void
    {
        [$token, $profile] = $this->createCustomer();
        $service = $this->createService();
        $booking = $this->createBooking($profile, $service, BookingStatus::Completed);
        $review = Review::factory()->for($profile)->create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
        ]);

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/reviews/{$review->id}")
            ->assertOk();

        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $booking->id, 'rating' => 4])
            ->assertCreated();
    }

    public function test_published_and_hidden_reviews_cannot_be_deleted(): void
    {
        [$token, $profile] = $this->createCustomer();
        $published = Review::factory()->for($profile)->published()->create();

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/reviews/{$published->id}")
            ->assertConflict()
            ->assertJsonPath('error_code', 'REVIEW_LOCKED');

        $this->assertDatabaseHas('reviews', ['id' => $published->id]);
    }

    public function test_customers_cannot_touch_other_customers_reviews(): void
    {
        [$token] = $this->createCustomer();
        $foreign = Review::factory()->create();

        $this
            ->withToken($token)
            ->putJson("/api/v1/reviews/{$foreign->id}", ['rating' => 1])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/reviews/{$foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_review_submission_is_rate_limited_per_customer(): void
    {
        [$token, $profile] = $this->createCustomer();
        $booking = $this->createBooking($profile, $this->createService(), BookingStatus::Completed);

        for ($i = 0; $i < 5; $i++) {
            $this
                ->withToken($token)
                ->postJson('/api/v1/reviews', ['booking_id' => $booking->id, 'rating' => 5]);
        }

        $this
            ->withToken($token)
            ->postJson('/api/v1/reviews', ['booking_id' => $booking->id, 'rating' => 5])
            ->assertTooManyRequests();
    }

    /**
     * @return array{string, CustomerProfile}
     */
    private function createCustomer(): array
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->for($user)->create();

        return [$user->createToken('customer-mobile')->plainTextToken, $profile];
    }

    private function createService(): Service
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

        ServiceModeOption::query()->create([
            'service_id' => $service->id,
            'mode' => ServiceMode::OneTime,
            'is_active' => true,
        ]);

        return $service;
    }

    private function createBooking(
        CustomerProfile $profile,
        Service $service,
        BookingStatus $status,
    ): Booking {
        $mode = ServiceModeOption::query()->where('service_id', $service->id)->firstOrFail();

        return Booking::query()->create([
            'booking_number' => sprintf('BK-%s-%06d', now()->format('Y'), $this->bookingSequence++),
            'customer_profile_id' => $profile->id,
            'service_id' => $service->id,
            'service_mode_id' => $mode->id,
            'status' => $status,
            'requested_date' => now()->subWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => ['city' => 'Mogadishu'],
        ]);
    }
}
