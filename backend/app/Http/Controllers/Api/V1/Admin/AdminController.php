<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\CreateAdminAction;
use App\Actions\Admin\DeleteAdminAction;
use App\Actions\Admin\GetAdminAction;
use App\Actions\Admin\ListAdminPermissionsAction;
use App\Actions\Admin\ListAdminsAction;
use App\Actions\Admin\UpdateAdminAction;
use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAdminRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAdminRequest;
use App\Http\Resources\Api\V1\Admin\AdminResource;
use App\Http\Resources\Api\V1\Admin\PermissionResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminController extends Controller
{
    public function index(
        Request $request,
        ListAdminsAction $listAdmins,
    ): JsonResponse {
        $role = $this->requestedRole($request);
        $status = $this->requestedStatus($request);

        if ($role === false || $status === false) {
            $errors = [];

            if ($role === false) {
                $errors['role'] = ['The selected role is invalid.'];
            }

            if ($status === false) {
                $errors['status'] = ['The selected status is invalid.'];
            }

            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                $errors,
            );
        }

        try {
            $admins = $listAdmins->handle(
                $role,
                $status,
                $this->requestedSearch($request),
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve admins.',
                'ADMINS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Admins retrieved successfully.',
            [
                'items' => AdminResource::collection($admins->getCollection()),
                'pagination' => [
                    'current_page' => $admins->currentPage(),
                    'per_page' => $admins->perPage(),
                    'total' => $admins->total(),
                    'last_page' => $admins->lastPage(),
                ],
            ],
        );
    }

    public function show(
        int $admin,
        GetAdminAction $getAdmin,
        ListAdminPermissionsAction $listAdminPermissions,
    ): JsonResponse {
        try {
            $targetAdmin = $getAdmin->handle($admin);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve admin.',
                'ADMIN_FETCH_FAILED',
                500,
            );
        }

        if ($targetAdmin === null) {
            return ApiResponse::error(
                'Admin not found.',
                'ADMIN_NOT_FOUND',
                404,
            );
        }

        try {
            $permissions = $listAdminPermissions->handle($targetAdmin);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve admin.',
                'ADMIN_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Admin retrieved successfully.',
            array_merge(
                (new AdminResource($targetAdmin))->resolve(),
                [
                    'effective_permissions' => PermissionResource::collection(
                        $permissions['effective_permissions'],
                    ),
                ],
            ),
        );
    }

    public function store(
        StoreAdminRequest $request,
        CreateAdminAction $createAdmin,
    ): JsonResponse {
        /** @var Admin $actor */
        $actor = $request->user();

        try {
            $admin = $createAdmin->handle($actor, $request->validated());
        } catch (DomainException $exception) {
            return $this->mapDomainException($exception, 'Failed to create admin.', 'ADMIN_CREATE_FAILED');
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create admin.',
                'ADMIN_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Admin created successfully.',
            new AdminResource($admin),
            201,
        );
    }

    public function update(
        UpdateAdminRequest $request,
        Admin $admin,
        UpdateAdminAction $updateAdmin,
    ): JsonResponse {
        /** @var Admin $actor */
        $actor = $request->user();

        try {
            $updatedAdmin = $updateAdmin->handle($actor, $admin, $request->validated());
        } catch (DomainException $exception) {
            return $this->mapDomainException($exception, 'Failed to update admin.', 'ADMIN_UPDATE_FAILED');
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update admin.',
                'ADMIN_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Admin updated successfully.',
            new AdminResource($updatedAdmin),
        );
    }

    public function destroy(
        Request $request,
        Admin $admin,
        DeleteAdminAction $deleteAdmin,
    ): JsonResponse {
        /** @var Admin $actor */
        $actor = $request->user();

        try {
            $deleteAdmin->handle($actor, $admin);
        } catch (DomainException $exception) {
            return $this->mapDomainException($exception, 'Failed to delete admin.', 'ADMIN_DELETE_FAILED');
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to delete admin.',
                'ADMIN_DELETE_FAILED',
                500,
            );
        }

        return ApiResponse::success('Admin deleted successfully.');
    }

    private function mapDomainException(
        DomainException $exception,
        string $fallbackMessage,
        string $fallbackCode,
    ): JsonResponse {
        return match ($exception->getMessage()) {
            'FORBIDDEN' => ApiResponse::error(
                'Only Super Admin may manage admins.',
                'FORBIDDEN',
                403,
            ),
            'SELF_DELETE_NOT_ALLOWED' => ApiResponse::error(
                'You cannot delete your own admin account.',
                'SELF_DELETE_NOT_ALLOWED',
                422,
            ),
            default => ApiResponse::error($fallbackMessage, $fallbackCode, 500),
        };
    }

    private function requestedRole(Request $request): AdminRole|null|false
    {
        if (! $request->filled('role')) {
            return null;
        }

        return AdminRole::tryFrom((string) $request->query('role')) ?? false;
    }

    private function requestedStatus(Request $request): AdminStatus|null|false
    {
        if (! $request->filled('status')) {
            return null;
        }

        return AdminStatus::tryFrom((string) $request->query('status')) ?? false;
    }

    private function requestedSearch(Request $request): ?string
    {
        if (! $request->filled('search')) {
            return null;
        }

        return trim((string) $request->query('search'));
    }
}
