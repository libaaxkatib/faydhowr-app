<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\CompleteFollowUpAction;
use App\Actions\Marketing\CreateFollowUpAction;
use App\Actions\Marketing\ListFollowUpsAction;
use App\Actions\Marketing\RescheduleFollowUpAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\CompleteFollowUpRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\ListFollowUpsRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\RescheduleFollowUpRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreFollowUpRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\FollowUpResource;
use App\Models\FollowUp;
use App\Models\MarketingRecord;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class FollowUpController extends Controller
{
    public function index(ListFollowUpsRequest $request, ListFollowUpsAction $action): JsonResponse
    {
        return ApiResponse::success('Follow-ups retrieved successfully.', FollowUpResource::collection($action->handle($request->validated())));
    }

    public function store(StoreFollowUpRequest $request, MarketingRecord $record, CreateFollowUpAction $action): JsonResponse
    {
        $followUp = $action->handle($record, $request->validated(), $request->user());

        return ApiResponse::success('Follow-up scheduled successfully.', new FollowUpResource($followUp), 201);
    }

    public function complete(CompleteFollowUpRequest $request, FollowUp $followUp, CompleteFollowUpAction $action): JsonResponse
    {
        $followUp = $action->handle($followUp, $request->validated('note'), $request->user());

        return ApiResponse::success('Follow-up marked completed.', new FollowUpResource($followUp));
    }

    public function reschedule(RescheduleFollowUpRequest $request, FollowUp $followUp, RescheduleFollowUpAction $action): JsonResponse
    {
        $followUp = $action->handle($followUp, $request->validated('follow_up_date'), $request->validated('note'), $request->user());

        return ApiResponse::success('Follow-up rescheduled successfully.', new FollowUpResource($followUp));
    }
}
