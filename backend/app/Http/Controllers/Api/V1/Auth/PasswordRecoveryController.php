<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ForgotPasswordAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Exceptions\Auth\OtpCooldownException;
use App\Exceptions\Auth\OtpRateLimitedException;
use App\Exceptions\Auth\ResetTokenInvalidException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PasswordRecoveryController extends Controller
{
    public function forgot(ForgotPasswordRequest $request, ForgotPasswordAction $forgotPassword): JsonResponse
    {
        try {
            if ($request->filled('email')) {
                $forgotPassword->handleEmail($request->string('email')->toString());
            } else {
                $forgotPassword->handlePhone($request->string('phone')->toString());
            }
        } catch (OtpCooldownException $exception) {
            return ApiResponse::error($exception->getMessage(), 'OTP_COOLDOWN', 429);
        } catch (OtpRateLimitedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'RATE_LIMITED', 429);
        }

        return ApiResponse::success('If this account exists, recovery instructions have been sent.');
    }

    public function reset(ResetPasswordRequest $request, ResetPasswordAction $resetPassword): JsonResponse
    {
        try {
            if ($request->filled('email')) {
                $resetPassword->handleEmail(
                    $request->string('email')->toString(),
                    $request->string('token')->toString(),
                    $request->string('password')->toString(),
                );
            } else {
                $resetPassword->handlePhone(
                    $request->string('phone')->toString(),
                    $request->string('token')->toString(),
                    $request->string('password')->toString(),
                );
            }
        } catch (ResetTokenInvalidException $exception) {
            return ApiResponse::error($exception->getMessage(), 'RESET_TOKEN_INVALID', 401);
        }

        return ApiResponse::success('Password has been reset.');
    }
}
