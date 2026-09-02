<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateDepartmentAction;
use App\Actions\Hr\DeleteDepartmentAction;
use App\Actions\Hr\ListDepartmentsAction;
use App\Actions\Hr\UpdateDepartmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreDepartmentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateDepartmentRequest;
use App\Http\Resources\Api\V1\Admin\Hr\DepartmentResource;
use App\Models\Department;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function index(ListDepartmentsAction $action): JsonResponse
    {
        return ApiResponse::success(
            'Departments retrieved successfully.',
            DepartmentResource::collection($action->handle()),
        );
    }

    public function store(StoreDepartmentRequest $request, CreateDepartmentAction $action): JsonResponse
    {
        $department = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Department created successfully.', new DepartmentResource($department), 201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department, UpdateDepartmentAction $action): JsonResponse
    {
        $department = $action->handle($department, $request->validated(), $request->user());

        return ApiResponse::success('Department updated successfully.', new DepartmentResource($department));
    }

    public function destroy(Department $department, DeleteDepartmentAction $action): JsonResponse
    {
        $action->handle($department, request()->user());

        return ApiResponse::success('Department deleted successfully.');
    }
}
