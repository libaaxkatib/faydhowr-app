<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetDashboardAction;
use App\Actions\Dashboard\GetDashboardAction as GetDashboardWidgetsAction;
use App\Contracts\Dashboard\DashboardMetadataBuilderInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\GetDashboardRequest;
use App\Http\Resources\Api\V1\Admin\DashboardResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class DashboardController extends Controller
{
    public function show(
        GetDashboardRequest $request,
        GetDashboardAction $getDashboard,
        GetDashboardWidgetsAction $getDashboardWidgets,
        DashboardMetadataBuilderInterface $metadataBuilder,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        $filter = $request->dateFilter();
        $startDate = $request->startDate();
        $endDate = $request->endDate();

        try {
            // The widgets action applies the request's date filter to the
            // query service first, so the legacy statistics block computed
            // below shares the exact same filter context and cache entries.
            $widgets = $getDashboardWidgets->handle($filter, $startDate, $endDate);

            $dashboard = $getDashboard->handle($admin, $request);
            $dashboard['widgets'] = $widgets;
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
            meta: $metadataBuilder->build($filter, $startDate, $endDate),
        );
    }
}
