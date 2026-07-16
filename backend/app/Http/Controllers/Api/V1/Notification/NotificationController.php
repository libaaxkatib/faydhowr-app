<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Notification\GetNotificationAction;
use App\Actions\Notification\GetUnreadNotificationCountAction;
use App\Actions\Notification\ListNotificationsAction;
use App\Actions\Notification\MarkAllNotificationsReadAction;
use App\Actions\Notification\MarkNotificationReadAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\ListNotificationsRequest;
use App\Http\Resources\Api\V1\Notification\NotificationResource;
use App\Models\Admin;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class NotificationController extends Controller
{
    public function index(
        ListNotificationsRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        ListNotificationsAction $listNotifications,
    ): JsonResponse {
        try {
            $recipient = $this->resolveRecipient($request, $getCustomerProfile);

            if ($recipient instanceof JsonResponse) {
                return $recipient;
            }

            $notifications = $listNotifications->handle(
                $recipient,
                $request->statusFilter(),
                $request->typeFilter(),
                $request->channelFilter(),
                $request->perPage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve notifications.',
                'NOTIFICATIONS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notifications retrieved successfully.',
            [
                'items' => NotificationResource::collection($notifications->getCollection()),
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ],
            ],
        );
    }

    public function show(
        Request $request,
        int $notification,
        GetCustomerProfileAction $getCustomerProfile,
        GetNotificationAction $getNotification,
    ): JsonResponse {
        try {
            $recipient = $this->resolveRecipient($request, $getCustomerProfile);

            if ($recipient instanceof JsonResponse) {
                return $recipient;
            }

            $model = $getNotification->handle($recipient, $notification);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve notification.',
                'NOTIFICATION_FETCH_FAILED',
                500,
            );
        }

        if ($model === null) {
            return $this->notificationNotFound();
        }

        return ApiResponse::success(
            'Notification retrieved successfully.',
            new NotificationResource($model),
        );
    }

    public function markRead(
        Request $request,
        int $notification,
        GetCustomerProfileAction $getCustomerProfile,
        MarkNotificationReadAction $markNotificationRead,
    ): JsonResponse {
        try {
            $recipient = $this->resolveRecipient($request, $getCustomerProfile);

            if ($recipient instanceof JsonResponse) {
                return $recipient;
            }

            $model = $markNotificationRead->handle($recipient, $notification);
        } catch (DomainException $exception) {
            if ($exception->getMessage() === 'NOTIFICATION_NOT_FOUND') {
                return $this->notificationNotFound();
            }

            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to mark notification as read.',
                'NOTIFICATION_READ_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification marked as read.',
            new NotificationResource($model),
        );
    }

    public function unreadCount(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        GetUnreadNotificationCountAction $getUnreadNotificationCount,
    ): JsonResponse {
        try {
            $recipient = $this->resolveRecipient($request, $getCustomerProfile);

            if ($recipient instanceof JsonResponse) {
                return $recipient;
            }

            $count = $getUnreadNotificationCount->handle($recipient);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve unread notification count.',
                'NOTIFICATIONS_UNREAD_COUNT_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Unread notification count retrieved successfully.',
            ['unread_count' => $count],
        );
    }

    public function markAllRead(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        MarkAllNotificationsReadAction $markAllNotificationsRead,
    ): JsonResponse {
        try {
            $recipient = $this->resolveRecipient($request, $getCustomerProfile);

            if ($recipient instanceof JsonResponse) {
                return $recipient;
            }

            $updated = $markAllNotificationsRead->handle($recipient);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to mark notifications as read.',
                'NOTIFICATIONS_READ_ALL_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notifications marked as read.',
            ['updated_count' => $updated],
        );
    }

    private function resolveRecipient(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
    ): Model|JsonResponse {
        $user = $request->user();

        if ($user instanceof Admin) {
            return $user;
        }

        if ($user instanceof User) {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return ApiResponse::error(
                    'Customer profile was not found for the authenticated user.',
                    'CUSTOMER_PROFILE_NOT_FOUND',
                    404,
                );
            }

            return $profile;
        }

        return ApiResponse::error('Unauthenticated.', 'UNAUTHENTICATED', 401);
    }

    private function notificationNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Notification not found.',
            'NOTIFICATION_NOT_FOUND',
            404,
        );
    }
}
