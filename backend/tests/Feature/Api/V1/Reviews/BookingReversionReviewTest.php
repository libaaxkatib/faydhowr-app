<?php

namespace Tests\Feature\Api\V1\Reviews;

use App\Enums\BookingStatus;
use App\Enums\Review\ReviewStatus;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingReversionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_reverting_a_completed_booking_hides_its_published_review_and_recalculates_aggregates(): void
    {
        $review = Review::factory()->published()->create(['rating' => 5]);
        $service = Service::query()->findOrFail($review->service_id);
        $service->forceFill(['average_rating' => 5.00, 'reviews_count' => 1])->save();

        $booking = Booking::query()->findOrFail($review->booking_id);
        $booking->update(['status' => BookingStatus::InProgress]);

        $review->refresh();
        $service->refresh();

        $this->assertSame(ReviewStatus::Hidden, $review->status);
        $this->assertSame(0, $service->reviews_count);
        $this->assertNull($service->average_rating);
        $this->assertDatabaseHas('reviews', ['id' => $review->id]);
    }

    public function test_reverting_a_completed_booking_hides_a_pending_review_too(): void
    {
        $review = Review::factory()->create();

        $booking = Booking::query()->findOrFail($review->booking_id);
        $booking->update(['status' => BookingStatus::Cancelled]);

        $this->assertSame(ReviewStatus::Hidden, $review->refresh()->status);
    }

    public function test_non_reverting_status_changes_leave_the_review_untouched(): void
    {
        $review = Review::factory()->published()->create();

        $booking = Booking::query()->findOrFail($review->booking_id);
        $booking->update(['customer_notes' => 'Updated notes only.']);

        $this->assertSame(ReviewStatus::Published, $review->refresh()->status);
    }

    public function test_status_changes_between_non_completed_statuses_do_not_touch_reviews(): void
    {
        $review = Review::factory()->published()->create();
        $booking = Booking::query()->findOrFail($review->booking_id);
        Booking::withoutEvents(function () use ($booking): void {
            $booking->update(['status' => BookingStatus::InProgress]);
        });

        $booking->refresh()->update(['status' => BookingStatus::Scheduled]);

        $this->assertSame(ReviewStatus::Published, $review->refresh()->status);
    }
}
