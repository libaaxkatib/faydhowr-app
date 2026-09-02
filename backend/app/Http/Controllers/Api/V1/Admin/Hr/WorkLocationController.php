<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateWorkLocationAction;
use App\Actions\Hr\DeleteWorkLocationAction;
use App\Actions\Hr\ListWorkLocationsAction;
use App\Actions\Hr\UpdateWorkLocationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreWorkLocationRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateWorkLocationRequest;
use App\Http\Resources\Api\V1\Admin\Hr\WorkLocationResource;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkLocationController extends Controller
{
    public function index(Request $request, ListWorkLocationsAction $action): JsonResponse
    {
        $clientCompanyId = $request->integer('client_company_id') ?: null;

        return ApiResponse::success(
            'Work locations retrieved successfully.',
            WorkLocationResource::collection($action->handle($clientCompanyId)),
        );
    }

    public function store(StoreWorkLocationRequest $request, CreateWorkLocationAction $action): JsonResponse
    {
        $workLocation = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Work location created successfully.', new WorkLocationResource($workLocation), 201);
    }

    public function update(UpdateWorkLocationRequest $request, WorkLocation $workLocation, UpdateWorkLocationAction $action): JsonResponse
    {
        $workLocation = $action->handle($workLocation, $request->validated(), $request->user());

        return ApiResponse::success('Work location updated successfully.', new WorkLocationResource($workLocation));
    }

    public function destroy(WorkLocation $workLocation, DeleteWorkLocationAction $action): JsonResponse
    {
        $action->handle($workLocation, request()->user());

        return ApiResponse::success('Work location deleted successfully.');
    }
}
