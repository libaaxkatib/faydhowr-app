<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RequestPhoneOtpAction;
use App\Actions\Auth\VerifyPhoneOtpAction;
use App\Exceptions\Auth\AccountRestrictedException;
use App\Exceptions\Auth\OtpAttemptsExceededException;
use App\Exceptions\Auth\OtpCooldownException;
use App\Exceptions\Auth\OtpExpiredException;
use App\Exceptions\Auth\OtpInvalidException;
use App\Exceptions\Auth\OtpRateLimitedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RequestPhoneOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyPhoneOtpRequest;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use App\Services\Auth\OtpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PhoneAuthController extends Controller
{
    public function requestOtp(RequestPhoneOtpRequest $request, RequestPhoneOtpAction $requestOtp): JsonResponse
    {
        if (config('auth_features.phone_otp_login') !== true) {
            return ApiResponse::error(
                'Phone login is currently unavailable.',
                'AUTH_METHOD_DISABLED',
                403,
            );
        }

        try {
            $requestOtp->handle($request->string('phone')->toString());
        } catch (OtpCooldownException $exception) {
            return ApiResponse::error($exception->getMessage(), 'OTP_COOLDOWN', 429);
        } catch (OtpRateLimitedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'RATE_LIMITED', 429);
        }

        return ApiResponse::success(
            'If this phone number is registered, a code has been sent.',
            [
                'expires_in' => OtpService::EXPIRY_SECONDS,
                'resend_after' => OtpService::RESEND_COOLDOWN_SECONDS,
            ],
        );
    }

    public function verify(VerifyPhoneOtpRequest $request, VerifyPhoneOtpAction $verifyOtp): JsonResponse
    {
        if (config('auth_features.phone_otp_login') !== true) {
            return ApiResponse::error(
                'Phone login is currently unavailable.',
                'AUTH_METHOD_DISABLED',
                403,
            );
        }

        try {
            $login = $verifyOtp->handle(
                $request->string('phone')->toString(),
                $request->string('otp')->toString(),
            );
        } catch (OtpInvalidException $exception) {
            return ApiResponse::error($exception->getMessage(), 'OTP_INVALID', 401);
        } catch (OtpExpiredException $exception) {
            return ApiResponse::error($exception->getMessage(), 'OTP_EXPIRED', 401);
        } catch (OtpAttemptsExceededException $exception) {
            return ApiResponse::error($exception->getMessage(), 'OTP_ATTEMPTS_EXCEEDED', 401);
        } catch (AccountRestrictedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'ACCOUNT_SUSPENDED', 403);
        }

        return ApiResponse::success('Login successful.', new AuthenticatedUserResource($login));
    }
}
