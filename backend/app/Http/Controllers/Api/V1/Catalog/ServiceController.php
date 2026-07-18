<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Contracts\Catalog\Services\ServiceCatalogServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Catalog\ListServicesRequest;
use App\Http\Requests\Api\V1\Catalog\SearchServicesRequest;
use App\Http\Resources\Api\V1\Catalog\ServiceCardResource;
use App\Http\Resources\Api\V1\Catalog\ServiceDetailResource;
use App\Models\Service;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    public function __construct(private ServiceCatalogServiceInterface $catalog) {}

    public function index(ListServicesRequest $request): JsonResponse
    {
        $paginator = $this->catalog->listServices($request->toFilters());

        return $this->paginatedCards('Services retrieved successfully.', $paginator);
    }

    public function show(string $slug): JsonResponse
    {
        $service = $this->catalog->getServiceBySlug($slug);

        if ($service === null) {
            return ApiResponse::error('Service not found.', 'NOT_FOUND', 404);
        }

        return ApiResponse::success(
            'Service retrieved successfully.',
            new ServiceDetailResource($service),
        );
    }

    public function search(SearchServicesRequest $request): JsonResponse
    {
        $paginator = $this->catalog->searchServices(
            $request->string('q')->toString(),
            $request->perPage(),
        );

        return $this->paginatedCards('Services retrieved successfully.', $paginator);
    }

    /**
     * @param  LengthAwarePaginator<int, Service>  $paginator
     */
    private function paginatedCards(string $message, LengthAwarePaginator $paginator): JsonResponse
    {
        return ApiResponse::success(
            $message,
            ['items' => ServiceCardResource::collection($paginator->items())],
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
