<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreatePracticalBatchAction;
use App\Actions\Hr\ListPracticalBatchesAction;
use App\Actions\Hr\UpdatePracticalBatchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StorePracticalBatchRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdatePracticalBatchRequest;
use App\Http\Resources\Api\V1\Admin\Hr\PracticalBatchResource;
use App\Models\PracticalBatch;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class PracticalBatchController extends Controller
{
    public function index(ListPracticalBatchesAction $action): JsonResponse
    {
        return ApiResponse::success('Practical batches retrieved successfully.', PracticalBatchResource::collection($action->handle()));
    }

    public function store(StorePracticalBatchRequest $request, CreatePracticalBatchAction $action): JsonResponse
    {
        $batch = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Practical batch created successfully.', new PracticalBatchResource($batch), 201);
    }

    public function update(UpdatePracticalBatchRequest $request, PracticalBatch $practicalBatch, UpdatePracticalBatchAction $action): JsonResponse
    {
        $batch = $action->handle($practicalBatch, $request->validated(), $request->user());

        return ApiResponse::success('Practical batch updated successfully.', new PracticalBatchResource($batch));
    }
}
