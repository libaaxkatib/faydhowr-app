<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Notification\CreateNotificationTemplateAction;
use App\Actions\Notification\ListNotificationTemplatesAction;
use App\Actions\Notification\UpdateNotificationTemplateAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreNotificationTemplateRequest;
use App\Http\Requests\Api\V1\Admin\UpdateNotificationTemplateRequest;
use App\Http\Resources\Api\V1\Admin\NotificationTemplateResource;
use App\Models\NotificationTemplate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class NotificationTemplateController extends Controller
{
    public function index(
        Request $request,
        ListNotificationTemplatesAction $listNotificationTemplates,
    ): JsonResponse {
        $status = $this->requestedStatus($request);
        $type = $this->requestedType($request);
        $channel = $this->requestedChannel($request);

        if ($status === false || $type === false || $channel === false) {
            $errors = [];

            if ($status === false) {
                $errors['status'] = ['The selected status is invalid.'];
            }

            if ($type === false) {
                $errors['type'] = ['The selected type is invalid.'];
            }

            if ($channel === false) {
                $errors['channel'] = ['The selected channel is invalid.'];
            }

            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                $errors,
            );
        }

        try {
            $templates = $listNotificationTemplates->handle(
                $status,
                $type,
                $channel,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve notification templates.',
                'NOTIFICATION_TEMPLATES_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification templates retrieved successfully.',
            [
                'items' => NotificationTemplateResource::collection($templates->getCollection()),
                'pagination' => [
                    'current_page' => $templates->currentPage(),
                    'per_page' => $templates->perPage(),
                    'total' => $templates->total(),
                    'last_page' => $templates->lastPage(),
                ],
            ],
        );
    }

    public function show(int $template): JsonResponse
    {
        $model = NotificationTemplate::query()->find($template);

        if ($model === null) {
            return ApiResponse::error(
                'Notification template not found.',
                'NOTIFICATION_TEMPLATE_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Notification template retrieved successfully.',
            new NotificationTemplateResource($model),
        );
    }

    public function store(
        StoreNotificationTemplateRequest $request,
        CreateNotificationTemplateAction $createNotificationTemplate,
    ): JsonResponse {
        try {
            $template = $createNotificationTemplate->handle($request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create notification template.',
                'NOTIFICATION_TEMPLATE_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification template created successfully.',
            new NotificationTemplateResource($template),
            201,
        );
    }

    public function update(
        int $template,
        UpdateNotificationTemplateRequest $request,
        UpdateNotificationTemplateAction $updateNotificationTemplate,
    ): JsonResponse {
        $model = NotificationTemplate::query()->find($template);

        if ($model === null) {
            return ApiResponse::error(
                'Notification template not found.',
                'NOTIFICATION_TEMPLATE_NOT_FOUND',
                404,
            );
        }

        try {
            $updated = $updateNotificationTemplate->handle($model, $request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update notification template.',
                'NOTIFICATION_TEMPLATE_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification template updated successfully.',
            new NotificationTemplateResource($updated),
        );
    }

    private function requestedStatus(Request $request): NotificationTemplateStatus|false|null
    {
        if (! $request->filled('status')) {
            return null;
        }

        return NotificationTemplateStatus::tryFrom((string) $request->input('status')) ?? false;
    }

    private function requestedType(Request $request): NotificationType|false|null
    {
        if (! $request->filled('type')) {
            return null;
        }

        return NotificationType::tryFrom((string) $request->input('type')) ?? false;
    }

    private function requestedChannel(Request $request): NotificationChannel|false|null
    {
        if (! $request->filled('channel')) {
            return null;
        }

        return NotificationChannel::tryFrom((string) $request->input('channel')) ?? false;
    }
}
