<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateOrUpdateEmployeeGuarantorAction;
use App\Actions\Hr\VerifyEmployeeGuarantorAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreEmployeeGuarantorRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeGuarantorResource;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeGuarantorController extends Controller
{
    public function show(Employee $employee): JsonResponse
    {
        $guarantor = $employee->guarantor()->with(['verifiedBy', 'createdBy'])->first();

        if (! $guarantor) {
            return ApiResponse::error('No guarantor information recorded yet.', 'EMPLOYEE_GUARANTOR_NOT_FOUND', 404);
        }

        return ApiResponse::success('Guarantor retrieved successfully.', new EmployeeGuarantorResource($guarantor));
    }

    public function store(StoreEmployeeGuarantorRequest $request, Employee $employee, CreateOrUpdateEmployeeGuarantorAction $action): JsonResponse
    {
        $guarantor = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Guarantor information saved successfully.', new EmployeeGuarantorResource($guarantor));
    }

    public function verify(Employee $employee, VerifyEmployeeGuarantorAction $action): JsonResponse
    {
        $guarantor = $employee->guarantor()->first();

        if (! $guarantor) {
            return ApiResponse::error('Guarantor information must be recorded before it can be verified.', 'EMPLOYEE_GUARANTOR_NOT_FOUND', 404);
        }

        $employee = $action->handle($employee, $guarantor, request()->user());

        return ApiResponse::success('Guarantor verified successfully.', new EmployeeResource($employee));
    }
}
