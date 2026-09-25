<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\GetEmployeePayrollSummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\GetEmployeePayrollSummaryRequest;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeePayrollController extends Controller
{
    public function summary(GetEmployeePayrollSummaryRequest $request, Employee $employee, GetEmployeePayrollSummaryAction $action): JsonResponse
    {
        $assignment = EmployeeWorkAssignment::query()->findOrFail($request->validated('work_assignment_id'));

        return ApiResponse::success(
            'Payroll summary retrieved successfully.',
            $action->handle($employee, $assignment, $request->validated('period')),
        );
    }
}
