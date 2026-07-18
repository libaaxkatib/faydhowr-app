<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Actions\Home\CreateBeforeAfterItemAction;
use App\Actions\Home\DeleteBeforeAfterItemAction;
use App\Actions\Home\UpdateBeforeAfterItemAction;
use App\Contracts\Home\Repositories\BeforeAfterItemRepositoryInterface;
use App\DataTransferObjects\Home\CreateBeforeAfterItemData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ListContentRequest;
use App\Http\Requests\Api\V1\Admin\Content\StoreBeforeAfterItemRequest;
use App\Http\Requests\Api\V1\Admin\Content\UpdateBeforeAfterItemRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBeforeAfterItemResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BeforeAfterItemController extends Controller
{
    public function __construct(private BeforeAfterItemRepositoryInterface $items) {}

    public function index(ListContentRequest $request): JsonResponse
    {
        $paginator = $this->items->paginateForAdmin($request->perPage());

        return ApiResponse::success(
            'Before and after items retrieved successfully.',
            ['items' => AdminBeforeAfterItemResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function store(StoreBeforeAfterItemRequest $request, CreateBeforeAfterItemAction $createItem): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $item = $createItem->handle(
            $admin,
            CreateBeforeAfterItemData::fromValidated($request->validated()),
        );

        return ApiResponse::success(
            'Before and after item created successfully.',
            new AdminBeforeAfterItemResource($item),
            201,
        );
    }

    public function update(UpdateBeforeAfterItemRequest $request, int $item, UpdateBeforeAfterItemAction $updateItem): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $galleryItem = $this->items->find($item);

        if ($galleryItem === null) {
            return ApiResponse::error('Before and after item not found.', 'NOT_FOUND', 404);
        }

        $galleryItem = $updateItem->handle($admin, $galleryItem, $request->validated());

        return ApiResponse::success(
            'Before and after item updated successfully.',
            new AdminBeforeAfterItemResource($galleryItem),
        );
    }

    public function destroy(Request $request, int $item, DeleteBeforeAfterItemAction $deleteItem): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user();

        $galleryItem = $this->items->find($item);

        if ($galleryItem === null) {
            return ApiResponse::error('Before and after item not found.', 'NOT_FOUND', 404);
        }

        $deleteItem->handle($admin, $galleryItem);

        return ApiResponse::success('Before and after item deleted successfully.');
    }
}
