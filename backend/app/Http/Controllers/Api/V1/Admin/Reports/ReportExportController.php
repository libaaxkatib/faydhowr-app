<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Actions\Report\CreateReportExportAction;
use App\Actions\Report\ListReportExportsAction;
use App\Actions\Report\NormalizeReportFiltersAction;
use App\Data\Reports\ReportCursorPagination;
use App\Exceptions\Reports\InvalidReportFilterException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CreateReportExportRequest;
use App\Http\Requests\Api\V1\Admin\ListReportExportsRequest;
use App\Http\Resources\Api\V1\Admin\ReportExportResource;
use App\Models\Admin;
use App\Models\Report;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ReportExportController extends Controller
{
    public function index(
        ListReportExportsRequest $request,
        ListReportExportsAction $listReportExports,
    ): JsonResponse {
        $exports = $listReportExports->handle(
            $request->historyFilters(),
            $request->cursorPagination(),
            $request->sortDirection(),
        );

        return ApiResponse::success('Report exports retrieved successfully.', [
            'data' => ReportExportResource::collection($exports->items()),
            'pagination' => ReportCursorPagination::metadata($exports),
        ]);
    }

    public function store(
        CreateReportExportRequest $request,
        Report $report,
        NormalizeReportFiltersAction $normalizeReportFilters,
        CreateReportExportAction $createReportExport,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $filters = $normalizeReportFilters->handle($request->reportFilters());
        } catch (InvalidReportFilterException $exception) {
            return ApiResponse::error($exception->getMessage(), 'INVALID_REPORT_FILTER', 422);
        }

        $export = $createReportExport->handle($admin, $report, $request->exportFormat(), $filters);

        return ApiResponse::success('Report export queued successfully.', [
            'export_id' => $export->id,
            'status' => $export->status->value,
        ], 202);
    }
}
