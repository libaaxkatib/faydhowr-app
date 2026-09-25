<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\ListMarketingEmployeesAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Marketing\MarketingEmployeeResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class MarketingEmployeeController extends Controller
{
    public function index(ListMarketingEmployeesAction $action): JsonResponse
    {
        return ApiResponse::success('Marketing employees retrieved successfully.', MarketingEmployeeResource::collection($action->handle()));
    }
}
