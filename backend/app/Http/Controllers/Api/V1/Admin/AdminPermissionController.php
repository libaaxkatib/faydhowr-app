<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\ListAdminPermissionsAction;
use App\Actions\Admin\UpdateAdminPermissionsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateAdminPermissionsRequest;
use App\Http\Resources\Api\V1\Admin\PermissionResource;
use App\Models\Admin;
use App\Models\Permission;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Throwable;

class AdminPermissionController extends Controller
{
    public function show(
        Admin $admin,
        ListAdminPermissionsAction $listAdminPermissions,
    ): JsonResponse {
        try {
            $permissions = $listAdminPermissions->handle($admin);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve admin permissions.',
                'ADMIN_PERMISSIONS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Admin permissions retrieved successfully.',
            $this->toPermissionPayload($permissions),
        );
    }

    public function update(
        Admin $admin,
        UpdateAdminPermissionsRequest $request,
        UpdateAdminPermissionsAction $updateAdminPermissions,
        ListAdminPermissionsAction $listAdminPermissions,
    ): JsonResponse {
        /** @var Admin $actor */
        $actor = $request->user();

        try {
            $updateAdminPermissions->handle(
                $actor,
                $admin,
                $request->validated('permissions'),
            );

            $permissions = $listAdminPermissions->handle($admin->fresh());
        } catch (DomainException $exception) {
            return match ($exception->getMessage()) {
                'FORBIDDEN' => ApiResponse::error(
                    'Only Super Admin may update direct admin permissions.',
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
                    'Failed to update admin permissions.',
                    'ADMIN_PERMISSIONS_UPDATE_FAILED',
                    500,
                ),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update admin permissions.',
                'ADMIN_PERMISSIONS_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Admin permissions updated successfully.',
            $this->toPermissionPayload($permissions),
        );
    }

    /**
     * @param  array{
     *     role: string,
     *     role_permissions: Collection<int, Permission>,
     *     direct_permissions: Collection<int, Permission>,
     *     effective_permissions: Collection<int, Permission>
     * }  $permissions
     * @return array<string, mixed>
     */
    private function toPermissionPayload(array $permissions): array
    {
        return [
            'role' => $permissions['role'],
            'role_permissions' => PermissionResource::collection($permissions['role_permissions']),
            'direct_permissions' => PermissionResource::collection($permissions['direct_permissions']),
            'effective_permissions' => PermissionResource::collection($permissions['effective_permissions']),
        ];
    }
}
