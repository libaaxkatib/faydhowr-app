<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreatePositionAction;
use App\Actions\Hr\DeletePositionAction;
use App\Actions\Hr\ListPositionsAction;
use App\Actions\Hr\UpdatePositionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StorePositionRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdatePositionRequest;
use App\Http\Resources\Api\V1\Admin\Hr\PositionResource;
use App\Models\Position;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PositionController extends Controller
{
    public function index(ListPositionsAction $action): JsonResponse
    {
        return ApiResponse::success(
            'Positions retrieved successfully.',
            PositionResource::collection($action->handle()),
        );
    }

    public function store(StorePositionRequest $request, CreatePositionAction $action): JsonResponse
    {
        $position = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Position created successfully.', new PositionResource($position), 201);
    }

    public function update(UpdatePositionRequest $request, Position $position, UpdatePositionAction $action): JsonResponse
    {
        $position = $action->handle($position, $request->validated(), $request->user());

        return ApiResponse::success('Position updated successfully.', new PositionResource($position));
    }

    public function destroy(Position $position, DeletePositionAction $action): JsonResponse
    {
        $action->handle($position, request()->user());

        return ApiResponse::success('Position deleted successfully.');
    }
}
