<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Actions\Home\ToggleServiceFeaturedAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\UpdateServiceFeaturedRequest;
use App\Models\Admin;
use App\Models\Service;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Featured curation toggle (API Design §18.11): the only piece of admin
 * services management shipping in Sprint 29 — full Admin Services CRUD
 * remains deferred.
 */
class ServiceFeaturedController extends Controller
{
    public function update(
        UpdateServiceFeaturedRequest $request,
        int $service,
        ToggleServiceFeaturedAction $toggleFeatured,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        $serviceModel = Service::query()->find($service);

        if ($serviceModel === null) {
            return ApiResponse::error('Service not found.', 'NOT_FOUND', 404);
        }

        $serviceModel = $toggleFeatured->handle(
            $admin,
            $serviceModel,
            $request->boolean('is_featured'),
            $request->filled('sort_order') ? $request->integer('sort_order') : null,
        );

        return ApiResponse::success(
            'Service featured state updated successfully.',
            [
                'id' => $serviceModel->id,
                'slug' => $serviceModel->slug,
                'name' => $serviceModel->name,
                'is_featured' => $serviceModel->is_featured,
                'sort_order' => $serviceModel->sort_order,
            ],
        );
    }
}
