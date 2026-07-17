<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Contracts\Settings\Services\AuditServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\ListSettingsAuditLogsRequest;
use App\Http\Resources\Api\V1\Admin\Settings\SettingsAuditLogResource;
use App\Models\SystemSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SettingsAuditLogController extends Controller
{
    public function __construct(private AuditServiceInterface $audit) {}

    public function index(ListSettingsAuditLogsRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SystemSetting::class);

        return ApiResponse::success(
            'Settings audit logs retrieved successfully.',
            SettingsAuditLogResource::collection($this->audit->logs($request->filters())),
        );
    }
}
