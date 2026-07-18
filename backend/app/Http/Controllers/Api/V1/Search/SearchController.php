<?php

namespace App\Http\Controllers\Api\V1\Search;

use App\Contracts\Search\Services\GlobalSearchServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Search\UnifiedSearchRequest;
use App\Http\Resources\Api\V1\Catalog\ServiceCardResource;
use App\Http\Resources\Api\V1\Product\ProductResource;
use App\Models\Product;
use App\Models\Service;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guest search endpoints (API Design §15): unified grouped search and
 * suggestions, both under the public-catalog throttle. Recent searches
 * are device-local — nothing is stored server-side.
 */
class SearchController extends Controller
{
    public function __construct(private GlobalSearchServiceInterface $search) {}

    public function index(UnifiedSearchRequest $request): JsonResponse
    {
        $results = $this->search->search($request->toQuery());

        return ApiResponse::success(
            'Search results retrieved successfully.',
            [
                'services' => $results['services'] === null
                    ? null
                    : $this->group($results['services'], ServiceCardResource::class),
                'products' => $results['products'] === null
                    ? null
                    : $this->group($results['products'], ProductResource::class),
            ],
        );
    }

    public function suggestions(Request $request): JsonResponse
    {
        return ApiResponse::success(
            'Search suggestions retrieved successfully.',
            ['items' => $this->search->suggestions($request->string('q')->toString())],
        );
    }

    /**
     * @param  LengthAwarePaginator<int, Service|Product>  $paginator
     * @param  class-string<ServiceCardResource|ProductResource>  $resource
     * @return array<string, mixed>
     */
    private function group(LengthAwarePaginator $paginator, string $resource): array
    {
        return [
            'items' => $resource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }
}
