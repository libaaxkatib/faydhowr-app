<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\GetMarketingDashboardAction;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingDashboardController extends Controller
{
    public function show(Request $request, GetMarketingDashboardAction $action): JsonResponse
    {
        return ApiResponse::success('Marketing dashboard retrieved successfully.', $action->handle($request->user()));
    }
}
