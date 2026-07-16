<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetDashboardAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\DashboardResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class DashboardController extends Controller
{
    public function show(
        Request $request,
        GetDashboardAction $getDashboard,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $dashboard = $getDashboard->handle($admin, $request);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve dashboard.',
                'DASHBOARD_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Dashboard retrieved successfully.',
            new DashboardResource($dashboard),
        );
    }
}
