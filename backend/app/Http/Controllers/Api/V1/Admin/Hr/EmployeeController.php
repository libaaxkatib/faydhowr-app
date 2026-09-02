<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateEmployeeAction;
use App\Actions\Hr\GetEmployeeAction;
use App\Actions\Hr\ListEmployeesAction;
use App\Actions\Hr\UpdateEmployeeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\ListEmployeesRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreEmployeeRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateEmployeeRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    public function index(ListEmployeesRequest $request, ListEmployeesAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated());

        return ApiResponse::success(
            'Employees retrieved successfully.',
            EmployeeResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): JsonResponse
    {
        $employee = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Employee registered successfully.', new EmployeeResource($employee), 201);
    }

    public function show(Employee $employee, GetEmployeeAction $action): JsonResponse
    {
        $employee = $action->handle($employee);

        return ApiResponse::success('Employee retrieved successfully.', new EmployeeResource($employee));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): JsonResponse
    {
        $employee = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Employee updated successfully.', new EmployeeResource($employee));
    }
}
