<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateEmployeeContractAction;
use App\Actions\Hr\MarkEmployeeContractSignedAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\MarkEmployeeContractSignedRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreEmployeeContractRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeContractResource;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeContractController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        $contracts = $employee->contracts()->with(['createdBy', 'signedDocument'])->get();

        return ApiResponse::success('Contracts retrieved successfully.', EmployeeContractResource::collection($contracts));
    }

    public function store(StoreEmployeeContractRequest $request, Employee $employee, CreateEmployeeContractAction $action): JsonResponse
    {
        $contract = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Contract issued successfully.', new EmployeeContractResource($contract), 201);
    }

    public function sign(
        MarkEmployeeContractSignedRequest $request,
        Employee $employee,
        EmployeeContract $contract,
        MarkEmployeeContractSignedAction $action,
    ): JsonResponse {
        if ((int) $contract->employee_id !== (int) $employee->id) {
            return ApiResponse::error('Contract was not found for this employee.', 'EMPLOYEE_CONTRACT_NOT_FOUND', 404);
        }

        $employee = $action->handle($employee, $contract, $request->validated(), $request->user());

        return ApiResponse::success('Contract marked signed successfully.', new EmployeeResource($employee));
    }
}
