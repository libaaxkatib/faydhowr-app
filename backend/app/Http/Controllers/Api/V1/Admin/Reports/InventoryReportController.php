<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Actions\Report\GenerateReportAction;
use App\Actions\Report\NormalizeReportFiltersAction;
use App\Enums\ReportType;
use App\Exceptions\Reports\InvalidReportFilterException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\GenerateReportRequest;
use App\Http\Resources\Api\V1\Admin\ReportResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class InventoryReportController extends Controller
{
    public function store(
        GenerateReportRequest $request,
        NormalizeReportFiltersAction $normalizeReportFilters,
        GenerateReportAction $generateReport,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $filters = $normalizeReportFilters->handle($request->reportFilters());
        } catch (InvalidReportFilterException $exception) {
            return ApiResponse::error($exception->getMessage(), 'INVALID_REPORT_FILTER', 422);
        }

        try {
            $result = $generateReport->handle($admin, ReportType::Inventory, $filters, $request->reportFormat(), $request->cursorPagination());
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to generate report.', 'REPORT_GENERATION_FAILED', 500);
        }

        return ApiResponse::success('Report generated successfully.', [
            'report' => new ReportResource($result['report']),
            'payload' => $result['payload'],
        ], 201);
    }
}
