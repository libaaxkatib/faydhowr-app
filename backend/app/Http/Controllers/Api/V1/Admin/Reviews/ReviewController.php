<?php

namespace App\Http\Controllers\Api\V1\Admin\Reviews;

use App\Contracts\Review\Services\ReviewServiceInterface;
use App\Exceptions\Review\ReviewNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reviews\ListAdminReviewsRequest;
use App\Http\Resources\Api\V1\Admin\Reviews\AdminReviewResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ReviewController extends Controller
{
    public function __construct(private ReviewServiceInterface $reviews) {}

    public function index(ListAdminReviewsRequest $request): JsonResponse
    {
        $paginator = $this->reviews->listForAdmin($request->toFilters());

        return ApiResponse::success(
            'Reviews retrieved successfully.',
            ['items' => AdminReviewResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function show(int $review): JsonResponse
    {
        try {
            $found = $this->reviews->findForAdmin($review);
        } catch (ReviewNotFoundException) {
            return $this->notFound();
        }

        return ApiResponse::success(
            'Review retrieved successfully.',
            new AdminReviewResource($found),
        );
    }

    public function approve(int $review): JsonResponse
    {
        try {
            $found = $this->reviews->findForAdmin($review);
        } catch (ReviewNotFoundException) {
            return $this->notFound();
        }

        $published = $this->reviews->publish($found);

        return ApiResponse::success(
            'Review published successfully.',
            new AdminReviewResource($published->load(['customerProfile', 'booking', 'service'])),
        );
    }

    public function hide(int $review): JsonResponse
    {
        try {
            $found = $this->reviews->findForAdmin($review);
        } catch (ReviewNotFoundException) {
            return $this->notFound();
        }

        $hidden = $this->reviews->hide($found);

        return ApiResponse::success(
            'Review hidden successfully.',
            new AdminReviewResource($hidden->load(['customerProfile', 'booking', 'service'])),
        );
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error('Review not found.', 'NOT_FOUND', 404);
    }
}
