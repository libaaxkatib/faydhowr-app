<?php

namespace App\Contracts\Review\Repositories;

use App\DataTransferObjects\Review\AdminReviewFiltersData;
use App\DataTransferObjects\Review\CreateReviewData;
use App\DataTransferObjects\Review\UpdateReviewData;
use App\Models\Review;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReviewRepositoryInterface
{
    public function create(CreateReviewData $data): Review;

    public function update(Review $review, UpdateReviewData $data): Review;

    public function delete(Review $review): void;

    public function existsForBooking(int $bookingId): bool;

    public function findByBookingId(int $bookingId): ?Review;

    public function findForOwner(int $reviewId, int $customerProfileId): ?Review;

    public function findWithRelations(int $reviewId): ?Review;

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    public function paginateForOwner(int $customerProfileId, int $perPage): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    public function paginatePublishedForService(int $serviceId, int $perPage): LengthAwarePaginator;

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    public function paginateForAdmin(AdminReviewFiltersData $filters): LengthAwarePaginator;

    /**
     * Recompute the service's cached average_rating / reviews_count from
     * published reviews only.
     */
    public function recalculateServiceAggregates(int $serviceId): void;
}
