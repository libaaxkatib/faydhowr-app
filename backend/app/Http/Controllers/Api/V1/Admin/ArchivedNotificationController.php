<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Notification\ListArchivedNotificationsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListArchivedNotificationsRequest;
use App\Http\Resources\Api\V1\Admin\ArchivedNotificationResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class ArchivedNotificationController extends Controller
{
    public function index(
        ListArchivedNotificationsRequest $request,
        ListArchivedNotificationsAction $listArchivedNotifications,
    ): JsonResponse {
        try {
            $archived = $listArchivedNotifications->handle(
                $request->recipientTypeFilter(),
                $request->typeFilter(),
                $request->channelFilter(),
                $request->statusFilter(),
                $request->archivedFromFilter(),
                $request->archivedToFilter(),
                $request->perPage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve archived notifications.',
                'ARCHIVED_NOTIFICATIONS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Archived notifications retrieved successfully.',
            [
                'items' => ArchivedNotificationResource::collection($archived->getCollection()),
                'pagination' => [
                    'current_page' => $archived->currentPage(),
                    'per_page' => $archived->perPage(),
                    'total' => $archived->total(),
                    'last_page' => $archived->lastPage(),
                ],
            ],
        );
    }
}
