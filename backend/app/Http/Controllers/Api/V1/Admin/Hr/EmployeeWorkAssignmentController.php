<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Actions\Hr\EndWorkAssignmentAction;
use App\Actions\Hr\UpdateWorkAssignmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\EndWorkAssignmentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreWorkAssignmentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateWorkAssignmentRequest;
use App\Http\Resources\Api\V1\Admin\Hr\WorkAssignmentResource;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeWorkAssignmentController extends Controller
{
    public function store(StoreWorkAssignmentRequest $request, Employee $employee, CreateWorkAssignmentAction $action): JsonResponse
    {
        $assignment = $action->handle($employee, $request->validated(), $request->user());

        return ApiResponse::success('Work assignment created successfully.', new WorkAssignmentResource($assignment), 201);
    }

    public function update(UpdateWorkAssignmentRequest $request, EmployeeWorkAssignment $workAssignment, UpdateWorkAssignmentAction $action): JsonResponse
    {
        $assignment = $action->handle($workAssignment, $request->validated(), $request->user());

        return ApiResponse::success('Work assignment updated successfully.', new WorkAssignmentResource($assignment));
    }

    public function end(EndWorkAssignmentRequest $request, EmployeeWorkAssignment $workAssignment, EndWorkAssignmentAction $action): JsonResponse
    {
        $assignment = $action->handle($workAssignment, $request->validated(), $request->user());

        return ApiResponse::success('Work assignment ended successfully.', new WorkAssignmentResource($assignment));
    }
}
