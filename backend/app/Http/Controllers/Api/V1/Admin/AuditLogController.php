<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\ListAuditLogsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ListAuditLogsRequest;
use App\Http\Resources\Api\V1\Admin\AuditLogResource;
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
}
