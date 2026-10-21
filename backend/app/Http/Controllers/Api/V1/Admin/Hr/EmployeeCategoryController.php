<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\ArchiveEmployeeCategoryAction;
use App\Actions\Hr\CreateEmployeeCategoryAction;
use App\Actions\Hr\ListEmployeeCategoriesAction;
use App\Actions\Hr\UpdateEmployeeCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreEmployeeCategoryRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateEmployeeCategoryRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeCategoryResource;
use App\Models\EmployeeCategory;
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

    public function store(StoreEmployeeCategoryRequest $request, CreateEmployeeCategoryAction $action): JsonResponse
    {
        $category = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Employee category created successfully.', new EmployeeCategoryResource($category), 201);
    }

    public function update(UpdateEmployeeCategoryRequest $request, EmployeeCategory $employeeCategory, UpdateEmployeeCategoryAction $action): JsonResponse
    {
        $category = $action->handle($employeeCategory, $request->validated(), $request->user());

        return ApiResponse::success('Employee category updated successfully.', new EmployeeCategoryResource($category));
    }

    public function archive(EmployeeCategory $employeeCategory, ArchiveEmployeeCategoryAction $action): JsonResponse
    {
        return ApiResponse::success('Employee category archived.', new EmployeeCategoryResource($action->handle($employeeCategory, true, request()->user())));
    }

    public function restore(EmployeeCategory $employeeCategory, ArchiveEmployeeCategoryAction $action): JsonResponse
    {
        return ApiResponse::success('Employee category restored.', new EmployeeCategoryResource($action->handle($employeeCategory, false, request()->user())));
    }
}
