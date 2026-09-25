<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\RecordEmployeeAdvanceAction;
use App\Actions\Hr\RecordEmployeeLeaveAction;
use App\Actions\Hr\RecordEmployeePaymentAction;
use App\Actions\Hr\RecordEmployeePenaltyAction;
use App\Actions\Hr\RecordEmployeePerformanceReviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\RecordEmployeeAdvanceRequest;
use App\Http\Requests\Api\V1\Admin\Hr\RecordEmployeeLeaveRequest;
use App\Http\Requests\Api\V1\Admin\Hr\RecordEmployeePaymentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\RecordEmployeePenaltyRequest;
use App\Http\Requests\Api\V1\Admin\Hr\RecordEmployeePerformanceReviewRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeRecordsController extends Controller
{
    public function storeLeave(RecordEmployeeLeaveRequest $request, Employee $employee, RecordEmployeeLeaveAction $action): JsonResponse
    {
        $employee = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Leave recorded successfully.', new EmployeeResource($employee), 201);
    }

    public function storePerformanceReview(
        RecordEmployeePerformanceReviewRequest $request,
        Employee $employee,
        RecordEmployeePerformanceReviewAction $action,
    ): JsonResponse {
        $employee = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Performance review recorded successfully.', new EmployeeResource($employee), 201);
    }

    public function storePayment(RecordEmployeePaymentRequest $request, Employee $employee, RecordEmployeePaymentAction $action): JsonResponse
    {
        $employee = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Payment recorded successfully.', new EmployeeResource($employee), 201);
    }

    public function storePenalty(RecordEmployeePenaltyRequest $request, Employee $employee, RecordEmployeePenaltyAction $action): JsonResponse
    {
        $employee = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Penalty recorded successfully.', new EmployeeResource($employee), 201);
    }

    public function storeAdvance(RecordEmployeeAdvanceRequest $request, Employee $employee, RecordEmployeeAdvanceAction $action): JsonResponse
    {
        $employee = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Salary advance recorded successfully.', new EmployeeResource($employee), 201);
    }
}
