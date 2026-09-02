<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\CreateXarunRecordAction;
use App\Actions\Marketing\UpdateXarunRecordAction;
use App\Enums\Marketing\MarketingRecordType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreXarunRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\UpdateXarunRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\MarketingRecordResource;
use App\Models\MarketingRecord;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class XarunController extends Controller
{
    public function store(StoreXarunRequest $request, CreateXarunRecordAction $action): JsonResponse
    {
        $record = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('XARUN registered successfully.', new MarketingRecordResource($record), 201);
    }

    public function update(UpdateXarunRequest $request, MarketingRecord $record, UpdateXarunRecordAction $action): JsonResponse
    {
        if ($record->type !== MarketingRecordType::Xarun) {
            return ApiResponse::error('This record is not a XARUN.', 'MARKETING_RECORD_TYPE_MISMATCH', 422);
        }

        $record = $action->handle($record, $request->validated(), $request->user());

        return ApiResponse::success('XARUN updated successfully.', new MarketingRecordResource($record));
    }
}
