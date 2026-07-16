<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Notification\CreateNotificationTemplateTranslationAction;
use App\Actions\Notification\ListNotificationTemplateTranslationsAction;
use App\Actions\Notification\UpdateNotificationTemplateTranslationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreNotificationTemplateTranslationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateNotificationTemplateTranslationRequest;
use App\Http\Resources\Api\V1\Admin\NotificationTemplateTranslationResource;
use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateTranslation;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Throwable;

class NotificationTemplateTranslationController extends Controller
{
    public function index(
        int $template,
        ListNotificationTemplateTranslationsAction $listTranslations,
    ): JsonResponse {
        $model = NotificationTemplate::query()->find($template);

        if ($model === null) {
            return $this->templateNotFound();
        }

        try {
            $translations = $listTranslations->handle($model);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve notification template translations.',
                'NOTIFICATION_TEMPLATE_TRANSLATIONS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification template translations retrieved successfully.',
            NotificationTemplateTranslationResource::collection($translations),
        );
    }

    public function store(
        int $template,
        StoreNotificationTemplateTranslationRequest $request,
        CreateNotificationTemplateTranslationAction $createTranslation,
    ): JsonResponse {
        $model = NotificationTemplate::query()->find($template);

        if ($model === null) {
            return $this->templateNotFound();
        }

        try {
            $translation = $createTranslation->handle($model, $request->validated());
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'TRANSLATION_LANGUAGE_EXISTS' => ApiResponse::error(
                    'A translation already exists for this language.',
                    'TRANSLATION_LANGUAGE_EXISTS',
                    422,
                ),
                default => ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create notification template translation.',
                'NOTIFICATION_TEMPLATE_TRANSLATION_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification template translation created successfully.',
            new NotificationTemplateTranslationResource($translation),
            201,
        );
    }

    public function update(
        int $template,
        int $translation,
        UpdateNotificationTemplateTranslationRequest $request,
        UpdateNotificationTemplateTranslationAction $updateTranslation,
    ): JsonResponse {
        $model = NotificationTemplate::query()->find($template);

        if ($model === null) {
            return $this->templateNotFound();
        }

        $translationModel = NotificationTemplateTranslation::query()
            ->whereKey($translation)
            ->where('notification_template_id', $model->id)
            ->first();

        if ($translationModel === null) {
            return ApiResponse::error(
                'Notification template translation not found.',
                'TRANSLATION_NOT_FOUND',
                404,
            );
        }

        try {
            $updated = $updateTranslation->handle($model, $translationModel, $request->validated());
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'TRANSLATION_NOT_FOUND' => ApiResponse::error(
                    'Notification template translation not found.',
                    'TRANSLATION_NOT_FOUND',
                    404,
                ),
                'TRANSLATION_LANGUAGE_EXISTS' => ApiResponse::error(
                    'A translation already exists for this language.',
                    'TRANSLATION_LANGUAGE_EXISTS',
                    422,
                ),
                default => ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update notification template translation.',
                'NOTIFICATION_TEMPLATE_TRANSLATION_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification template translation updated successfully.',
            new NotificationTemplateTranslationResource($updated),
        );
    }

    private function templateNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Notification template not found.',
            'NOTIFICATION_TEMPLATE_NOT_FOUND',
            404,
        );
    }
}
