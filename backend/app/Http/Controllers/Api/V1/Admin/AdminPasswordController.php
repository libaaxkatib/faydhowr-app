<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\ChangeOwnPasswordAction;
use App\Actions\Admin\ResetAdminPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ChangeOwnPasswordRequest;
use App\Http\Requests\Api\V1\Admin\ResetAdminPasswordRequest;
use App\Models\Admin;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Throwable;

class AdminPasswordController extends Controller
{
    public function updateOwn(
        ChangeOwnPasswordRequest $request,
        ChangeOwnPasswordAction $changeOwnPassword,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $changeOwnPassword->handle(
                $admin,
                $request->validated(),
                $admin->currentAccessToken()?->id,
            );
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'CURRENT_PASSWORD_INCORRECT' => ApiResponse::error(
                    'Current password is incorrect.',
                    'CURRENT_PASSWORD_INCORRECT',
                    422,
                ),
                default => ApiResponse::error(
                    'Failed to change password.',
                    'PASSWORD_CHANGE_FAILED',
                    500,
                ),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to change password.',
                'PASSWORD_CHANGE_FAILED',
                500,
            );
        }

        return ApiResponse::success('Password changed successfully.');
    }

    public function reset(
        ResetAdminPasswordRequest $request,
        Admin $admin,
        ResetAdminPasswordAction $resetAdminPassword,
    ): JsonResponse {
        /** @var Admin $actor */
        $actor = $request->user();

        try {
            $resetAdminPassword->handle($actor, $admin, $request->validated());
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'FORBIDDEN' => ApiResponse::error(
                    'Only Super Admin may reset another admin\'s password.',
                    'FORBIDDEN',
                    403,
                ),
                default => ApiResponse::error(
                    'Failed to reset password.',
                    'PASSWORD_RESET_FAILED',
                    500,
                ),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to reset password.',
                'PASSWORD_RESET_FAILED',
                500,
            );
        }

        return ApiResponse::success('Password reset successfully.');
    }
}
