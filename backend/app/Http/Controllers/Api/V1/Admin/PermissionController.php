<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\ListPermissionsAction;
use App\Actions\Admin\UpdateRolePermissionsAction;
use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateRolePermissionsRequest;
use App\Http\Resources\Api\V1\Admin\PermissionResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Throwable;

class PermissionController extends Controller
{
    public function index(ListPermissionsAction $listPermissions): JsonResponse
    {
        try {
            $permissions = $listPermissions->handle();
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve permissions.',
                'PERMISSIONS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Permissions retrieved successfully.',
            PermissionResource::collection($permissions),
        );
    }

    public function updateRolePermissions(
        string $role,
        UpdateRolePermissionsRequest $request,
        UpdateRolePermissionsAction $updateRolePermissions,
    ): JsonResponse {
        $adminRole = AdminRole::tryFrom($role);

        if ($adminRole === null) {
            return ApiResponse::error(
                'Role not found.',
                'ROLE_NOT_FOUND',
                404,
            );
        }

        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $permissions = $updateRolePermissions->handle(
                $admin,
                $adminRole,
                $request->validated('permissions'),
            );
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'FORBIDDEN' => ApiResponse::error(
                    'Only Super Admin may update role permissions.',
                    'FORBIDDEN',
                    403,
                ),
                'SUPER_ADMIN_PERMISSIONS_IMMUTABLE' => ApiResponse::error(
                    'Super Admin permissions are implicit and cannot be persisted.',
                    'SUPER_ADMIN_PERMISSIONS_IMMUTABLE',
                    422,
                ),
                'INVALID_PERMISSIONS' => ApiResponse::error(
                    'One or more permissions are invalid.',
                    'INVALID_PERMISSIONS',
                    422,
                ),
                default => ApiResponse::error(
                    'Failed to update role permissions.',
                    'ROLE_PERMISSIONS_UPDATE_FAILED',
                    500,
                ),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update role permissions.',
                'ROLE_PERMISSIONS_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Role permissions updated successfully.',
            [
                'role' => $adminRole->value,
                'permissions' => PermissionResource::collection($permissions),
            ],
        );
    }
}
