<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CancelWorkforceRequestAction;
use App\Actions\Hr\ConfirmWorkforceRequestMatchAction;
use App\Actions\Hr\CreateWorkforceRequestAction;
use App\Actions\Hr\GetWorkforceRequestCandidatesAction;
use App\Actions\Hr\ListWorkforceRequestsAction;
use App\Actions\Hr\UpdateWorkforceRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\ConfirmWorkforceRequestMatchRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreWorkforceRequestRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateWorkforceRequestRequest;
use App\Http\Resources\Api\V1\Admin\Hr\WorkforceRequestResource;
use App\Models\Employee;
use App\Models\WorkforceRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class WorkforceRequestController extends Controller
{
    public function index(ListWorkforceRequestsAction $action): JsonResponse
    {
        return ApiResponse::success('Workforce requests retrieved successfully.', WorkforceRequestResource::collection($action->handle()));
    }

    public function store(StoreWorkforceRequestRequest $request, CreateWorkforceRequestAction $action): JsonResponse
    {
        $workforceRequest = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Workforce request created successfully.', new WorkforceRequestResource($workforceRequest), 201);
    }

    public function update(UpdateWorkforceRequestRequest $request, WorkforceRequest $workforceRequest, UpdateWorkforceRequestAction $action): JsonResponse
    {
        $workforceRequest = $action->handle($workforceRequest, $request->validated(), $request->user());

        return ApiResponse::success('Workforce request updated successfully.', new WorkforceRequestResource($workforceRequest));
    }

    public function cancel(WorkforceRequest $workforceRequest, CancelWorkforceRequestAction $action): JsonResponse
    {
        $workforceRequest = $action->handle($workforceRequest, request()->user());

        return ApiResponse::success('Workforce request cancelled.', new WorkforceRequestResource($workforceRequest));
    }

    public function candidates(WorkforceRequest $workforceRequest, GetWorkforceRequestCandidatesAction $action): JsonResponse
    {
        return ApiResponse::success('Candidates retrieved successfully.', $action->handle($workforceRequest));
    }

    public function confirm(
        ConfirmWorkforceRequestMatchRequest $request,
        WorkforceRequest $workforceRequest,
        ConfirmWorkforceRequestMatchAction $action,
    ): JsonResponse {
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));
        $workforceRequest = $action->handle($workforceRequest, $employee, $request->validated('notes'), $request->user());

        return ApiResponse::success('Match confirmed successfully.', new WorkforceRequestResource($workforceRequest));
    }
}
