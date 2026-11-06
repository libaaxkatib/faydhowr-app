<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\ListAuditLogsAction;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListAuditLogsRequest;
use App\Http\Resources\Api\V1\Admin\AuditLogResource;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class AuditLogController extends Controller
{
    public function index(
        ListAuditLogsRequest $request,
        ListAuditLogsAction $listAuditLogs,
    ): JsonResponse {
        try {
            $auditLogs = $listAuditLogs->handle($request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve audit logs.',
                'AUDIT_LOGS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Audit logs retrieved successfully.',
            [
                'items' => AuditLogResource::collection($auditLogs->getCollection()),
                'pagination' => [
                    'current_page' => $auditLogs->currentPage(),
                    'per_page' => $auditLogs->perPage(),
                    'total' => $auditLogs->total(),
                    'last_page' => $auditLogs->lastPage(),
                ],
            ],
        );
    }

    /**
     * The choices the Audit Log screen's filters offer, so the screen needs no call to the Admin Users API (which is Super-Admin-only now):
     * the admins who appear in the log, the entity types it really holds (the stored value, which is what the entity filter matches exactly)
     * and every audit action the system can record.
     */
    public function filters(): JsonResponse
    {
        $admins = Admin::withTrashed()
            ->whereIn('id', AuditLog::query()->whereNotNull('admin_id')->select('admin_id'))
            ->orderBy('full_name')
            ->limit(500)
            ->get(['id', 'full_name'])
            ->map(fn (Admin $admin): array => ['id' => $admin->id, 'name' => $admin->full_name])
            ->all();

        $entityTypes = AuditLog::query()
            ->whereNotNull('entity_type')
            ->distinct()
            ->orderBy('entity_type')
            ->limit(500)
            ->pluck('entity_type')
            ->map(fn (string $type): array => ['value' => $type, 'label' => str_contains($type, '\\') ? substr($type, (int) strrpos($type, '\\') + 1) : $type])
            ->all();

        return ApiResponse::success('Audit log filters retrieved successfully.', [
            'admins' => $admins,
            'entity_types' => $entityTypes,
            'actions' => array_map(fn (AuditAction $action): string => $action->value, AuditAction::cases()),
        ]);
    }
}
