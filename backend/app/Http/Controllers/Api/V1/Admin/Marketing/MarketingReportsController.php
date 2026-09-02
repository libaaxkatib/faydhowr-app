<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\GetMarketingReportsSummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\GetMarketingReportsSummaryRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MarketingReportsController extends Controller
{
    public function summary(GetMarketingReportsSummaryRequest $request, GetMarketingReportsSummaryAction $action): JsonResponse
    {
        $summary = $action->handle(
            $request->validated('from'),
            $request->validated('to'),
            $request->validated('assigned_team_id') ? (int) $request->validated('assigned_team_id') : null,
        );

        return ApiResponse::success('Marketing report summary retrieved successfully.', $summary);
    }
}
