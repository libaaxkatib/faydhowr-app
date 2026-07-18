<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Contracts\Catalog\Services\ServiceCatalogServiceInterface;
use App\Contracts\Review\Services\ReviewServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reviews\ListReviewsRequest;
use App\Http\Resources\Api\V1\Reviews\PublicReviewResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ServiceReviewController extends Controller
{
    public function __construct(
        private ServiceCatalogServiceInterface $catalog,
        private ReviewServiceInterface $reviews,
    ) {}

    /**
     * Guest endpoint (API Design §8A.5): published reviews only, newest first.
     */
    public function index(ListReviewsRequest $request, string $slug): JsonResponse
    {
        $service = $this->catalog->getServiceBySlug($slug);

        if ($service === null) {
            return ApiResponse::error('Service not found.', 'NOT_FOUND', 404);
        }

        $paginator = $this->reviews->listPublishedForService($service, $request->perPage());

        return ApiResponse::success(
            'Reviews retrieved successfully.',
            ['items' => PublicReviewResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }
}
