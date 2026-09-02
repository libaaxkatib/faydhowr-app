<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\GetHrReportsSummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\GetHrReportsSummaryRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HrReportsController extends Controller
{
    public function summary(GetHrReportsSummaryRequest $request, GetHrReportsSummaryAction $action): JsonResponse
    {
        $summary = $action->handle(
            $request->validated('from'),
            $request->validated('to'),
            $request->only(['client_company_id', 'work_location_id', 'assignment_status']),
        );

        return ApiResponse::success('HR report summary retrieved successfully.', $summary);
    }
}
