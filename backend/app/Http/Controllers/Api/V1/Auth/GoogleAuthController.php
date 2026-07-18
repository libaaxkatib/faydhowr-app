<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GoogleSignInAction;
use App\Exceptions\Auth\AccountRestrictedException;
use App\Exceptions\Auth\GoogleTokenInvalidException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\GoogleSignInRequest;
use App\Http\Resources\Api\V1\AuthenticatedUserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class GoogleAuthController extends Controller
{
    public function store(GoogleSignInRequest $request, GoogleSignInAction $googleSignIn): JsonResponse
    {
        if (config('auth_features.google_login') !== true) {
            return ApiResponse::error(
                'Google login is currently unavailable.',
                'AUTH_METHOD_DISABLED',
                403,
            );
        }

        try {
            $login = $googleSignIn->handle($request->string('id_token')->toString());
        } catch (GoogleTokenInvalidException $exception) {
            return ApiResponse::error($exception->getMessage(), 'GOOGLE_TOKEN_INVALID', 401);
        } catch (AccountRestrictedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'ACCOUNT_SUSPENDED', 403);
        }

        return ApiResponse::success('Login successful.', new AuthenticatedUserResource($login));
    }
}
