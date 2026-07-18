<?php

namespace App\Http\Controllers\Api\V1\Reviews;

use App\Contracts\Review\Services\ReviewServiceInterface;
use App\Exceptions\Review\ReviewAlreadyExistsException;
use App\Exceptions\Review\ReviewBookingNotFoundException;
use App\Exceptions\Review\ReviewLockedException;
use App\Exceptions\Review\ReviewNotEligibleException;
use App\Exceptions\Review\ReviewNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reviews\ListReviewsRequest;
use App\Http\Requests\Api\V1\Reviews\StoreReviewRequest;
use App\Http\Requests\Api\V1\Reviews\UpdateReviewRequest;
use App\Http\Resources\Api\V1\Reviews\ReviewResource;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private ReviewServiceInterface $reviews) {}

    public function store(StoreReviewRequest $request): JsonResponse
    {
        $profile = $this->resolveProfile($request);

        if ($profile === null) {
            return $this->profileNotFound();
        }

        try {
            $review = $this->reviews->submit(
                $profile,
                $request->integer('booking_id'),
                $request->integer('rating'),
                $request->filled('title') ? (string) $request->string('title') : null,
                $request->filled('comment') ? (string) $request->string('comment') : null,
            );
        } catch (ReviewBookingNotFoundException) {
            return ApiResponse::error('Booking not found.', 'NOT_FOUND', 404);
        } catch (ReviewNotEligibleException $exception) {
            return ApiResponse::error($exception->getMessage(), 'REVIEW_NOT_ELIGIBLE', 409);
        } catch (ReviewAlreadyExistsException $exception) {
            return ApiResponse::error($exception->getMessage(), 'REVIEW_ALREADY_EXISTS', 409);
        }

        return ApiResponse::success(
            'Review submitted successfully. It is pending approval.',
            new ReviewResource($review->load(['booking', 'service'])),
            201,
        );
    }

    public function index(ListReviewsRequest $request): JsonResponse
    {
        $profile = $this->resolveProfile($request);

        if ($profile === null) {
            return $this->profileNotFound();
        }

        $paginator = $this->reviews->listForCustomer($profile, $request->perPage());

        return ApiResponse::success(
            'Reviews retrieved successfully.',
            ['items' => ReviewResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function update(UpdateReviewRequest $request, int $review): JsonResponse
    {
        $profile = $this->resolveProfile($request);

        if ($profile === null) {
            return $this->profileNotFound();
        }

        try {
            $updated = $this->reviews->update($profile, $review, $request->toData());
        } catch (ReviewNotFoundException) {
            return $this->reviewNotFound();
        } catch (ReviewLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'REVIEW_LOCKED', 409);
        }

        return ApiResponse::success(
            'Review updated successfully.',
            new ReviewResource($updated->load(['booking', 'service'])),
        );
    }

    public function destroy(Request $request, int $review): JsonResponse
    {
        $profile = $this->resolveProfile($request);

        if ($profile === null) {
            return $this->profileNotFound();
        }

        try {
            $this->reviews->delete($profile, $review);
        } catch (ReviewNotFoundException) {
            return $this->reviewNotFound();
        } catch (ReviewLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'REVIEW_LOCKED', 409);
        }

        return ApiResponse::success('Review deleted successfully.');
    }

    private function resolveProfile(Request $request): ?CustomerProfile
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->customerProfile;
    }

    private function profileNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Customer profile not found.',
            'CUSTOMER_PROFILE_NOT_FOUND',
            404,
        );
    }

    private function reviewNotFound(): JsonResponse
    {
        return ApiResponse::error('Review not found.', 'NOT_FOUND', 404);
    }
}
