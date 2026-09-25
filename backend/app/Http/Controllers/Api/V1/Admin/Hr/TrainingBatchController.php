<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\AddTrainingBatchParticipantAction;
use App\Actions\Hr\CompleteTrainingBatchAction;
use App\Actions\Hr\CreateTrainingBatchAction;
use App\Actions\Hr\ListTrainingBatchesAction;
use App\Actions\Hr\RemoveTrainingBatchParticipantAction;
use App\Actions\Hr\UpdateTrainingBatchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\AddTrainingBatchParticipantRequest;
use App\Http\Requests\Api\V1\Admin\Hr\CompleteTrainingBatchRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreTrainingBatchRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateTrainingBatchRequest;
use App\Http\Resources\Api\V1\Admin\Hr\TrainingBatchResource;
use App\Models\Employee;
use App\Models\TrainingBatch;
use App\Models\TrainingBatchParticipant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TrainingBatchController extends Controller
{
    public function index(ListTrainingBatchesAction $action): JsonResponse
    {
        return ApiResponse::success('Training batches retrieved successfully.', TrainingBatchResource::collection($action->handle()));
    }

    public function store(StoreTrainingBatchRequest $request, CreateTrainingBatchAction $action): JsonResponse
    {
        $batch = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Training batch created successfully.', new TrainingBatchResource($batch), 201);
    }

    public function update(UpdateTrainingBatchRequest $request, TrainingBatch $trainingBatch, UpdateTrainingBatchAction $action): JsonResponse
    {
        $batch = $action->handle($trainingBatch, $request->validated(), $request->user());

        return ApiResponse::success('Training batch updated successfully.', new TrainingBatchResource($batch));
    }

    public function addParticipant(
        AddTrainingBatchParticipantRequest $request,
        TrainingBatch $trainingBatch,
        AddTrainingBatchParticipantAction $action,
    ): JsonResponse {
        $employee = Employee::query()->findOrFail($request->validated('employee_id'));
        $batch = $action->handle($trainingBatch, $employee, $request->user());

        return ApiResponse::success('Participant added successfully.', new TrainingBatchResource($batch));
    }

    public function removeParticipant(
        TrainingBatch $trainingBatch,
        TrainingBatchParticipant $participant,
        RemoveTrainingBatchParticipantAction $action,
    ): JsonResponse {
        if ((int) $participant->training_batch_id !== (int) $trainingBatch->id) {
            return ApiResponse::error('Participant was not found in this batch.', 'TRAINING_BATCH_PARTICIPANT_NOT_FOUND', 404);
        }

        $batch = $action->handle($trainingBatch, $participant, request()->user());

        return ApiResponse::success('Participant removed successfully.', new TrainingBatchResource($batch));
    }

    public function complete(CompleteTrainingBatchRequest $request, TrainingBatch $trainingBatch, CompleteTrainingBatchAction $action): JsonResponse
    {
        $batch = $action->handle($trainingBatch, $request->validated('absent_employee_ids', []), $request->user());

        return ApiResponse::success('Training batch marked completed.', new TrainingBatchResource($batch));
    }
}
