<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Notification\ListNotificationPreferencesAction;
use App\Actions\Notification\UpdateNotificationPreferencesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\UpdateNotificationPreferencesRequest;
use App\Http\Resources\Api\V1\Notification\NotificationPreferenceResource;
use App\Models\Admin;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class NotificationPreferenceController extends Controller
{
    public function index(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        ListNotificationPreferencesAction $listNotificationPreferences,
    ): JsonResponse {
        try {
            $recipient = $this->resolveRecipient($request, $getCustomerProfile);

            if ($recipient instanceof JsonResponse) {
                return $recipient;
            }

            $preferences = $listNotificationPreferences->handle($recipient);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve notification preferences.',
                'NOTIFICATION_PREFERENCES_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification preferences retrieved successfully.',
            NotificationPreferenceResource::collection($preferences),
        );
    }

    public function update(
        UpdateNotificationPreferencesRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        UpdateNotificationPreferencesAction $updateNotificationPreferences,
    ): JsonResponse {
        try {
            $recipient = $this->resolveRecipient($request, $getCustomerProfile);

            if ($recipient instanceof JsonResponse) {
                return $recipient;
            }

            $preferences = $updateNotificationPreferences->handle(
                $recipient,
                $request->preferences(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update notification preferences.',
                'NOTIFICATION_PREFERENCES_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Notification preferences updated successfully.',
            NotificationPreferenceResource::collection($preferences),
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
}
