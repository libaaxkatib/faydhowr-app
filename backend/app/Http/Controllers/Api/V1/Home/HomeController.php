<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Contracts\Home\Services\HomeServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Home\ListHomeSectionRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Guest Home endpoints (API Design §5): the aggregate plus the eight
 * approved section endpoints, all served from the shared 5-minute cache
 * and throttled by the public-catalog limiter.
 */
class HomeController extends Controller
{
    public function __construct(private HomeServiceInterface $home) {}

    public function index(): JsonResponse
    {
        $aggregate = $this->home->aggregate();

        return ApiResponse::success(
            'Home content retrieved successfully.',
            ['sections' => $aggregate['sections']],
            200,
            $aggregate['meta'],
        );
    }

    public function heroBanners(): JsonResponse
    {
        return $this->sectionList('Hero banners retrieved successfully.', $this->home->heroBanners());
    }

    public function serviceCategories(): JsonResponse
    {
        return $this->sectionList('Service categories retrieved successfully.', $this->home->serviceCategories());
    }

    public function featuredServices(): JsonResponse
    {
        return $this->sectionList('Featured services retrieved successfully.', $this->home->featuredServices());
    }

    public function storeProducts(): JsonResponse
    {
        return $this->sectionList('Store products retrieved successfully.', $this->home->storeProducts());
    }

    public function beforeAfter(ListHomeSectionRequest $request): JsonResponse
    {
        return $this->paginatedSection(
            'Before and after gallery retrieved successfully.',
            $this->home->beforeAfter($request->page(), $request->perPage()),
        );
    }

    public function reviews(ListHomeSectionRequest $request): JsonResponse
    {
        return $this->paginatedSection(
            'Reviews retrieved successfully.',
            $this->home->reviews($request->page(), $request->perPage()),
        );
    }

    public function faq(ListHomeSectionRequest $request): JsonResponse
    {
        return $this->paginatedSection(
            'FAQ retrieved successfully.',
            $this->home->faqs($request->page(), $request->perPage()),
        );
    }

    public function contact(): JsonResponse
    {
        return ApiResponse::success(
            'Contact information retrieved successfully.',
            $this->home->contact(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sectionList(string $message, array $items): JsonResponse
    {
        return ApiResponse::success($message, ['items' => $items]);
    }

    /**
     * @param  array{items: list<array<string, mixed>>, meta: array<string, int>}  $section
     */
    private function paginatedSection(string $message, array $section): JsonResponse
    {
        return ApiResponse::success(
            $message,
            ['items' => $section['items']],
            200,
            $section['meta'],
        );
    }
}
