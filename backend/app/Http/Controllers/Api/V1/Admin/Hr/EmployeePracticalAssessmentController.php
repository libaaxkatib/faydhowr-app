<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreatePracticalAssessmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StorePracticalAssessmentRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeePracticalAssessmentResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeePracticalAssessmentController extends Controller
{
    public function store(StorePracticalAssessmentRequest $request, Employee $employee, CreatePracticalAssessmentAction $action): JsonResponse
    {
        $assessment = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success(
            'Practical assessment recorded successfully.',
            new EmployeePracticalAssessmentResource($assessment),
            201,
        );
    }
}
