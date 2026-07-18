<?php

namespace App\Services\Review;

use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Contracts\Review\Repositories\ReviewRepositoryInterface;
use App\Contracts\Review\Services\ReviewServiceInterface;
use App\DataTransferObjects\Review\AdminReviewFiltersData;
use App\DataTransferObjects\Review\CreateReviewData;
use App\DataTransferObjects\Review\UpdateReviewData;
use App\Enums\BookingStatus;
use App\Enums\Customer\ActivityType;
use App\Enums\Review\ReviewStatus;
use App\Exceptions\Review\ReviewAlreadyExistsException;
use App\Exceptions\Review\ReviewBookingNotFoundException;
use App\Exceptions\Review\ReviewLockedException;
use App\Exceptions\Review\ReviewNotEligibleException;
use App\Exceptions\Review\ReviewNotFoundException;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReviewService implements ReviewServiceInterface
{
    public function __construct(
        private ReviewRepositoryInterface $reviews,
        private CustomerActivityServiceInterface $activities,
    ) {}

    public function submit(
        CustomerProfile $profile,
        int $bookingId,
        int $rating,
        ?string $title,
        ?string $comment,
    ): Review {
        $booking = Booking::query()
            ->whereKey($bookingId)
            ->where('customer_profile_id', $profile->id)
            ->first();

        if ($booking === null) {
            throw ReviewBookingNotFoundException::make();
        }

        if ($booking->status !== BookingStatus::Completed) {
            throw ReviewNotEligibleException::make();
        }

        if ($this->reviews->existsForBooking($booking->id)) {
            throw ReviewAlreadyExistsException::make();
        }

        return DB::transaction(function () use ($profile, $booking, $rating, $title, $comment): Review {
            $review = $this->reviews->create(new CreateReviewData(
                customerProfileId: $profile->id,
                bookingId: $booking->id,
                serviceId: (int) $booking->service_id,
                rating: $rating,
                title: $title,
                comment: $comment,
            ));

            $this->activities->record(
                $profile,
                ActivityType::ReviewSubmitted,
                ActivityType::ReviewSubmitted->label(),
                $review,
            );

            return $review;
        });
    }

    public function listForCustomer(CustomerProfile $profile, int $perPage): LengthAwarePaginator
    {
        return $this->reviews->paginateForOwner($profile->id, $perPage);
    }

    public function update(CustomerProfile $profile, int $reviewId, UpdateReviewData $data): Review
    {
        $review = $this->findOwnedPendingReview($profile, $reviewId);

        return $this->reviews->update($review, $data);
    }

    public function delete(CustomerProfile $profile, int $reviewId): void
    {
        $review = $this->findOwnedPendingReview($profile, $reviewId);

        $this->reviews->delete($review);
    }

    public function listPublishedForService(Service $service, int $perPage): LengthAwarePaginator
    {
        return $this->reviews->paginatePublishedForService($service->id, $perPage);
    }

    public function listForAdmin(AdminReviewFiltersData $filters): LengthAwarePaginator
    {
        return $this->reviews->paginateForAdmin($filters);
    }

    public function findForAdmin(int $reviewId): Review
    {
        $review = $this->reviews->findWithRelations($reviewId);

        if ($review === null) {
            throw ReviewNotFoundException::make();
        }

        return $review;
    }

    public function publish(Review $review): Review
    {
        return $this->moderate($review, ReviewStatus::Published);
    }

    public function hide(Review $review): Review
    {
        return $this->moderate($review, ReviewStatus::Hidden);
    }

    public function hideForBookingReversion(Booking $booking): void
    {
        $review = $this->reviews->findByBookingId($booking->id);

        if ($review === null || $review->status === ReviewStatus::Hidden) {
            return;
        }

        $this->moderate($review, ReviewStatus::Hidden);
    }

    /**
     * Aggregates are recalculated only on publish/hide (SRS FR-093.7),
     * never on submission.
     */
    private function moderate(Review $review, ReviewStatus $status): Review
    {
        return DB::transaction(function () use ($review, $status): Review {
            $review->forceFill(['status' => $status])->save();

            $this->reviews->recalculateServiceAggregates((int) $review->service_id);

            return $review->refresh();
        });
    }

    private function findOwnedPendingReview(CustomerProfile $profile, int $reviewId): Review
    {
        $review = $this->reviews->findForOwner($reviewId, $profile->id);

        if ($review === null) {
            throw ReviewNotFoundException::make();
        }

        if (! $review->isPending()) {
            throw ReviewLockedException::make();
        }

        return $review;
    }
}
