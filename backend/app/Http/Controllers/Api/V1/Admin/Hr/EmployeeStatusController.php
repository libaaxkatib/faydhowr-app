<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\ConfirmEmployeeGuarantorAction;
use App\Actions\Hr\UpdateEmployeeStatusAction;
use App\Enums\EmployeeStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateEmployeeStatusRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeStatusController extends Controller
{
    public function update(UpdateEmployeeStatusRequest $request, Employee $employee, UpdateEmployeeStatusAction $action): JsonResponse
    {
        $employee = $action->handle(
            $employee,
            EmployeeStatus::from($request->validated('status')),
            $request->validated('note'),
            $request->user(),
        );

        return ApiResponse::success('Employee status updated successfully.', new EmployeeResource($employee));
    }

    public function confirmGuarantor(Employee $employee, ConfirmEmployeeGuarantorAction $action): JsonResponse
    {
        $employee = $action->handle($employee, request()->user());

        return ApiResponse::success('Guarantor confirmed successfully.', new EmployeeResource($employee));
    }
}
