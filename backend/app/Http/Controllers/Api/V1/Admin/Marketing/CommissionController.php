<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\CreateCommissionRateAction;
use App\Actions\Marketing\CreateCommissionRecordAction;
use App\Actions\Marketing\ListCommissionRatesAction;
use App\Actions\Marketing\ListCommissionRecordsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreCommissionRateRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreCommissionRecordRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\CommissionRateResource;
use App\Http\Resources\Api\V1\Admin\Marketing\CommissionRecordResource;
use App\Models\MarketingRecord;
use App\Support\ApiResponse;
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
        $rate = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Commission rate configured successfully.', new CommissionRateResource($rate), 201);
    }

    public function records(Request $request, ListCommissionRecordsAction $action): JsonResponse
    {
        $paginator = $action->handle($request->query());

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

    public function storeRecord(StoreCommissionRecordRequest $request, MarketingRecord $record, CreateCommissionRecordAction $action): JsonResponse
    {
        $commissionRecord = $action->handle($record, $request->validated(), $request->user());

        return ApiResponse::success('Commission entry logged successfully.', new CommissionRecordResource($commissionRecord), 201);
    }
}
