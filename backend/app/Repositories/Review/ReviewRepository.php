<?php

namespace App\Repositories\Review;

use App\Contracts\Review\Repositories\ReviewRepositoryInterface;
use App\DataTransferObjects\Review\AdminReviewFiltersData;
use App\DataTransferObjects\Review\CreateReviewData;
use App\DataTransferObjects\Review\UpdateReviewData;
use App\Enums\Review\ReviewStatus;
use App\Models\Review;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReviewRepository implements ReviewRepositoryInterface
{
    public function create(CreateReviewData $data): Review
    {
        return Review::query()->create([
            'customer_profile_id' => $data->customerProfileId,
            'booking_id' => $data->bookingId,
            'service_id' => $data->serviceId,
            'rating' => $data->rating,
            'title' => $data->title,
            'comment' => $data->comment,
            'status' => ReviewStatus::Pending,
        ]);
    }

    public function update(Review $review, UpdateReviewData $data): Review
    {
        $review->fill([
            'rating' => $data->rating,
            'title' => $data->title,
            'comment' => $data->comment,
        ])->save();

        return $review->refresh();
    }

    public function delete(Review $review): void
    {
        $review->delete();
    }

    public function existsForBooking(int $bookingId): bool
    {
        return Review::query()->where('booking_id', $bookingId)->exists();
    }

    public function findByBookingId(int $bookingId): ?Review
    {
        return Review::query()->where('booking_id', $bookingId)->first();
    }

    public function findForOwner(int $reviewId, int $customerProfileId): ?Review
    {
        return Review::query()
            ->whereKey($reviewId)
            ->where('customer_profile_id', $customerProfileId)
            ->first();
    }

    public function findWithRelations(int $reviewId): ?Review
    {
        return Review::query()
            ->with(['customerProfile', 'booking', 'service'])
            ->find($reviewId);
    }

    public function paginateForOwner(int $customerProfileId, int $perPage): LengthAwarePaginator
    {
        return Review::query()
            ->with(['booking', 'service'])
            ->where('customer_profile_id', $customerProfileId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function paginatePublishedForService(int $serviceId, int $perPage): LengthAwarePaginator
    {
        return Review::query()
            ->with('customerProfile')
            ->where('service_id', $serviceId)
            ->where('status', ReviewStatus::Published->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function paginatePublished(int $perPage): LengthAwarePaginator
    {
        return Review::query()
            ->with('customerProfile')
            ->where('status', ReviewStatus::Published->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function paginateForAdmin(AdminReviewFiltersData $filters): LengthAwarePaginator
    {
        $query = Review::query()->with(['customerProfile', 'booking', 'service']);

        if ($filters->status !== null) {
            $query->where('status', $filters->status->value);
        }

        if ($filters->serviceId !== null) {
            $query->where('service_id', $filters->serviceId);
        }

        if ($filters->rating !== null) {
            $query->where('rating', $filters->rating);
        }

        if ($filters->from !== null) {
            $query->whereDate('created_at', '>=', $filters->from);
        }

        if ($filters->to !== null) {
            $query->whereDate('created_at', '<=', $filters->to);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters->perPage);
    }

    public function recalculateServiceAggregates(int $serviceId): void
    {
        $stats = Review::query()
            ->where('service_id', $serviceId)
            ->where('status', ReviewStatus::Published->value)
            ->selectRaw('COUNT(*) as reviews_total, AVG(rating) as rating_average')
            ->first();

        $total = (int) ($stats->reviews_total ?? 0);

        Service::query()->whereKey($serviceId)->update([
            'reviews_count' => $total,
            'average_rating' => $total > 0
                ? number_format((float) $stats->rating_average, 2, '.', '')
                : null,
        ]);
    }
}
