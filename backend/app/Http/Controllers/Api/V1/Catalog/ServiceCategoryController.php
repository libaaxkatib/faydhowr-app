<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Contracts\Catalog\Services\ServiceCatalogServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Catalog\ServiceCategoryResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ServiceCategoryController extends Controller
{
    public function index(ServiceCatalogServiceInterface $catalog): JsonResponse
    {
        return ApiResponse::success(
            'Service categories retrieved successfully.',
            ServiceCategoryResource::collection($catalog->listCategories()),
        );
    }
}
