<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\CreateCommissionRateAction;
use App\Actions\Marketing\CreateCommissionRecordAction;
use App\Actions\Marketing\ListCommissionRatesAction;
use App\Actions\Marketing\ListCommissionRecordsAction;
use App\Actions\Marketing\UpdateCommissionRecordAmountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreCommissionRateRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreCommissionRecordRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\UpdateCommissionRecordRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\CommissionRateResource;
use App\Http\Resources\Api\V1\Admin\Marketing\CommissionRecordResource;
use App\Models\CommissionRecord;
use App\Models\MarketingRecord;
use App\Support\ApiResponse;
use App\Support\Marketing\MarketingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function rates(ListCommissionRatesAction $action): JsonResponse
    {
        return ApiResponse::success('Commission rates retrieved successfully.', CommissionRateResource::collection($action->handle()));
    }

    public function storeRate(StoreCommissionRateRequest $request, CreateCommissionRateAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted($request->user(), 'Only the Marketing Manager can configure commission rates.');
        $rate = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Commission rate configured successfully.', new CommissionRateResource($rate), 201);
    }

    public function records(Request $request, ListCommissionRecordsAction $action): JsonResponse
    {
        $paginator = $action->handle($request->query(), $request->user());

        return ApiResponse::success(
            'Commission records retrieved successfully.',
            CommissionRecordResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function updateRecord(UpdateCommissionRecordRequest $request, CommissionRecord $commissionRecord, UpdateCommissionRecordAmountAction $action): JsonResponse
    {
        MarketingScope::requireUnrestricted($request->user(), 'Only the Marketing Manager can record a commission amount.');

        $commissionRecord = $action->handle($commissionRecord, $request->validated(), $request->user());

        return ApiResponse::success('Commission amount recorded successfully.', new CommissionRecordResource($commissionRecord));
    }

    public function storeRecord(StoreCommissionRecordRequest $request, MarketingRecord $record, CreateCommissionRecordAction $action): JsonResponse
    {
        $actor = $request->user();
        MarketingScope::authorizeRecord($record, $actor);
        MarketingScope::guardAssignment($actor, null, (int) $request->validated('admin_id'));

        if ($request->validated('amount') !== null) {
            MarketingScope::requireUnrestricted($actor, 'Only the Marketing Manager can record a commission amount.');
        }

        $commissionRecord = $action->handle($record, $request->validated(), $actor);

        return ApiResponse::success('Commission entry logged successfully.', new CommissionRecordResource($commissionRecord), 201);
    }
}
