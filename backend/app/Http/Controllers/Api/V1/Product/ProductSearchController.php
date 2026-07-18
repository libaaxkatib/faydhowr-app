<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Contracts\Search\Services\GlobalSearchServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Search\SearchProductsRequest;
use App\Http\Resources\Api\V1\Product\ProductResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Guest product search (API Design §15.2): ranked over name and
 * description; out-of-stock products remain visible with their
 * availability state. Uses the standard meta pagination format.
 */
class ProductSearchController extends Controller
{
    public function __construct(private GlobalSearchServiceInterface $search) {}

    public function __invoke(SearchProductsRequest $request): JsonResponse
    {
        $paginator = $this->search->searchProducts(
            $request->string('q')->trim()->toString(),
            $request->categoryId(),
            $request->perPage(),
        );

        return ApiResponse::success(
            'Products retrieved successfully.',
            ['items' => ProductResource::collection($paginator->items())],
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
