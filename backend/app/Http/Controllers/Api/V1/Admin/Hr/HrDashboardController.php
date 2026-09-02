<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\GetHrDashboardAction;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class HrDashboardController extends Controller
{
    public function show(GetHrDashboardAction $action): JsonResponse
    {
        return ApiResponse::success('HR dashboard retrieved successfully.', $action->handle());
    }
}
