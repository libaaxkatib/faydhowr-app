<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\RecordPracticalDecisionAction;
use App\Enums\PracticalAssessmentResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StorePracticalAssessmentRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeePracticalAssessmentController extends Controller
{
    public function store(StorePracticalAssessmentRequest $request, Employee $employee, RecordPracticalDecisionAction $action): JsonResponse
    {
        $employee = $action->handle(
            $employee,
            PracticalAssessmentResult::from($request->validated('result')),
            $request->validated('assessment_date'),
            $request->validated('practical_batch_id'),
            $request->validated('notes'),
            $request->user(),
        );

        return ApiResponse::success('Practical decision recorded successfully.', new EmployeeResource($employee), 201);
    }
}
