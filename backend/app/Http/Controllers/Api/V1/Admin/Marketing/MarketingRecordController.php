<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\AssignMarketingRecordAction;
use App\Actions\Marketing\GetMarketingRecordAction;
use App\Actions\Marketing\ListMarketingRecordsAction;
use App\Actions\Marketing\UpdateMarketingRecordStatusAction;
use App\Enums\Marketing\MarketingRecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\AssignMarketingRecordRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\ListMarketingRecordsRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\UpdateMarketingRecordStatusRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\MarketingRecordResource;
use App\Models\MarketingRecord;
use App\Support\ApiResponse;
use App\Support\Marketing\MarketingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingRecordController extends Controller
{
    public function index(ListMarketingRecordsRequest $request, ListMarketingRecordsAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated(), $request->user());

        return ApiResponse::success(
            'Marketing records retrieved successfully.',
            MarketingRecordResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function show(Request $request, MarketingRecord $record, GetMarketingRecordAction $action): JsonResponse
    {
        $record = $action->handle(MarketingScope::authorizeRecord($record, $request->user()));

        return ApiResponse::success('Marketing record retrieved successfully.', new MarketingRecordResource($record));
    }

    public function updateStatus(UpdateMarketingRecordStatusRequest $request, MarketingRecord $record, UpdateMarketingRecordStatusAction $action): JsonResponse
    {
        MarketingScope::authorizeRecord($record, $request->user());
        $record = $action->handle($record, MarketingRecordStatus::from($request->validated('status')), $request->user());

        return ApiResponse::success('Marketing record status updated successfully.', new MarketingRecordResource($record));
    }

    public function assign(AssignMarketingRecordRequest $request, MarketingRecord $record, AssignMarketingRecordAction $action): JsonResponse
    {
        MarketingScope::authorizeRecord($record, $request->user());
        MarketingScope::guardAssignment($request->user(), $request->validated('assigned_team_id'), $request->validated('assigned_admin_id'));
        $record = $action->handle($record, $request->validated(), $request->user());

        return ApiResponse::success('Marketing record assignment updated successfully.', new MarketingRecordResource($record));
    }
}
