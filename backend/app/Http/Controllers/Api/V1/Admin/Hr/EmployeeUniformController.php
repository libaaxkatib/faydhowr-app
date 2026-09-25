<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\ConfirmEmployeeUniformAction;
use App\Actions\Hr\UpdateEmployeeUniformAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateEmployeeUniformRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeUniformResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeUniformController extends Controller
{
    public function show(Employee $employee): JsonResponse
    {
        $uniform = $employee->uniform()->with('confirmedBy')->first();

        if (! $uniform) {
            return ApiResponse::error('No uniform record yet.', 'EMPLOYEE_UNIFORM_NOT_FOUND', 404);
        }

        return ApiResponse::success('Uniform retrieved successfully.', new EmployeeUniformResource($uniform));
    }

    public function update(UpdateEmployeeUniformRequest $request, Employee $employee, UpdateEmployeeUniformAction $action): JsonResponse
    {
        $uniform = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Uniform status updated successfully.', new EmployeeUniformResource($uniform));
    }

    public function confirm(Employee $employee, ConfirmEmployeeUniformAction $action): JsonResponse
    {
        $uniform = $employee->uniform()->first();

        if (! $uniform) {
            return ApiResponse::error('Uniform information must be recorded before it can be confirmed.', 'EMPLOYEE_UNIFORM_NOT_FOUND', 404);
        }

        $employee = $action->handle($employee, $uniform, request()->user());

        return ApiResponse::success('Uniform confirmed successfully.', new EmployeeResource($employee));
    }
}
