<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Actions\Admin\GetAuthenticatedAdminAction;
use App\Actions\Admin\GetEffectivePermissionsAction;
use App\Actions\Admin\LoginAdminAction;
use App\Actions\Admin\LogoutAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Auth\LoginAdminRequest;
use App\Http\Resources\Api\V1\Admin\AdminResource;
use App\Http\Resources\Api\V1\Admin\PermissionResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminAuthController extends Controller
{
    public function login(
        LoginAdminRequest $request,
        LoginAdminAction $loginAdmin,
    ): JsonResponse {
        try {
            $login = $loginAdmin->handle($request->validated());
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'ADMIN_ACCOUNT_INACTIVE' => ApiResponse::error(
                    'Admin account is inactive.',
                    'ADMIN_ACCOUNT_INACTIVE',
                    403,
                ),
                default => ApiResponse::error(
                    'Invalid email or password.',
                    'INVALID_CREDENTIALS',
                    401,
                ),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Login failed.', 'LOGIN_FAILED', 500);
        }

        return ApiResponse::success('Login successful.', [
            'admin' => new AdminResource($login['admin']),
            'access_token' => $login['access_token'],
            'token_type' => $login['token_type'],
        ]);
    }

    public function logout(
        Request $request,
        LogoutAdminAction $logoutAdmin,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $logoutAdmin->handle($admin);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Logout failed.', 'LOGOUT_FAILED', 500);
        }

        return ApiResponse::success('Logout successful.');
    }

    public function me(
        Request $request,
        GetAuthenticatedAdminAction $getAuthenticatedAdmin,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $authenticatedAdmin = $getAuthenticatedAdmin->handle($admin);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve authenticated admin.',
                'ADMIN_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Authenticated admin retrieved successfully.',
            new AdminResource($authenticatedAdmin),
        );
    }

    /**
     * The calling admin's own effective permissions - role-derived plus any
     * direct overrides, or every permission implicitly for Super Admin. Unlike
     * GET /admin/permissions (which requires roles.manage), this is self-only
     * and needs no extra permission: an admin can always see their own access.
     */
    public function permissions(
        Request $request,
        GetEffectivePermissionsAction $getEffectivePermissions,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $permissions = $getEffectivePermissions->handle($admin, $request);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve effective permissions.',
                'EFFECTIVE_PERMISSIONS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Effective permissions retrieved successfully.',
            PermissionResource::collection($permissions),
        );
    }
}
