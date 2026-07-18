<?php

namespace App\Contracts\Review\Services;

use App\DataTransferObjects\Review\AdminReviewFiltersData;
use App\DataTransferObjects\Review\UpdateReviewData;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReviewServiceInterface
{
    /**
     * Create a pending review for an owned, completed, not-yet-reviewed booking.
     */
    public function submit(
        CustomerProfile $profile,
        int $bookingId,
        int $rating,
        ?string $title,
        ?string $comment,
    ): Review;

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    public function listForCustomer(CustomerProfile $profile, int $perPage): LengthAwarePaginator;

    /**
     * Update an owned review; allowed only while pending.
     */
    public function update(CustomerProfile $profile, int $reviewId, UpdateReviewData $data): Review;

    /**
     * Delete an owned review; allowed only while pending. Frees the booking
     * for a new review submission.
     */
    public function delete(CustomerProfile $profile, int $reviewId): void;

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    public function listPublishedForService(Service $service, int $perPage): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    public function listForAdmin(AdminReviewFiltersData $filters): LengthAwarePaginator;

    public function findForAdmin(int $reviewId): Review;

    /**
     * Admin moderation: publish the review and recalculate aggregates.
     */
    public function publish(Review $review): Review;

    /**
     * Admin moderation: hide the review and recalculate aggregates.
     */
    public function hide(Review $review): Review;

    /**
     * Auto-hide the booking's review after a completed booking is reverted
     * to a non-completed status. Never deletes the review.
     */
    public function hideForBookingReversion(Booking $booking): void;
}
