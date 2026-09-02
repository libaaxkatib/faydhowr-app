<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\ListEmployeeCategoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeCategoryResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeCategoryController extends Controller
{
    public function index(ListEmployeeCategoriesAction $action): JsonResponse
    {
        return ApiResponse::success(
            'Employee categories retrieved successfully.',
            EmployeeCategoryResource::collection($action->handle()),
        );
    }
}
