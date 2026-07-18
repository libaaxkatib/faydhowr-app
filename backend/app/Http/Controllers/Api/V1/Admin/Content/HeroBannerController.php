<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Actions\Home\CreateHeroBannerAction;
use App\Actions\Home\DeleteHeroBannerAction;
use App\Actions\Home\UpdateHeroBannerAction;
use App\Contracts\Home\Repositories\HeroBannerRepositoryInterface;
use App\DataTransferObjects\Home\CreateHeroBannerData;
use App\Exceptions\Home\HeroBannerInvalidException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ListContentRequest;
use App\Http\Requests\Api\V1\Admin\Content\StoreHeroBannerRequest;
use App\Http\Requests\Api\V1\Admin\Content\UpdateHeroBannerRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminHeroBannerResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeroBannerController extends Controller
{
    public function __construct(private HeroBannerRepositoryInterface $heroBanners) {}

    public function index(ListContentRequest $request): JsonResponse
    {
        $paginator = $this->heroBanners->paginateForAdmin($request->perPage());

        return ApiResponse::success(
            'Hero banners retrieved successfully.',
            ['items' => AdminHeroBannerResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function store(StoreHeroBannerRequest $request, CreateHeroBannerAction $createBanner): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $banner = $createBanner->handle(
            $admin,
            CreateHeroBannerData::fromValidated($request->validated()),
        );

        return ApiResponse::success(
            'Hero banner created successfully.',
            new AdminHeroBannerResource($banner),
            201,
        );
    }

    public function update(UpdateHeroBannerRequest $request, int $banner, UpdateHeroBannerAction $updateBanner): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $heroBanner = $this->heroBanners->find($banner);

        if ($heroBanner === null) {
            return ApiResponse::error('Hero banner not found.', 'NOT_FOUND', 404);
        }

        try {
            $heroBanner = $updateBanner->handle($admin, $heroBanner, $request->validated());
        } catch (HeroBannerInvalidException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                'VALIDATION_ERROR',
                422,
                $exception->errors,
            );
        }

        return ApiResponse::success(
            'Hero banner updated successfully.',
            new AdminHeroBannerResource($heroBanner),
        );
    }

    public function destroy(Request $request, int $banner, DeleteHeroBannerAction $deleteBanner): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $heroBanner = $this->heroBanners->find($banner);

        if ($heroBanner === null) {
            return ApiResponse::error('Hero banner not found.', 'NOT_FOUND', 404);
        }

        $deleteBanner->handle($admin, $heroBanner);

        return ApiResponse::success('Hero banner deleted successfully.');
    }
}
