<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\CreateProjectRecordAction;
use App\Actions\Marketing\UpdateProjectRecordAction;
use App\Enums\Marketing\MarketingRecordType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreProjectRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\UpdateProjectRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\MarketingRecordResource;
use App\Models\MarketingRecord;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProjectController extends Controller
{
    public function store(StoreProjectRequest $request, CreateProjectRecordAction $action): JsonResponse
    {
        $record = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('PROJECT registered successfully.', new MarketingRecordResource($record), 201);
    }

    public function update(UpdateProjectRequest $request, MarketingRecord $record, UpdateProjectRecordAction $action): JsonResponse
    {
        if ($record->type !== MarketingRecordType::Project) {
            return ApiResponse::error('This record is not a PROJECT.', 'MARKETING_RECORD_TYPE_MISMATCH', 422);
        }

        $record = $action->handle($record, $request->validated(), $request->user());

        return ApiResponse::success('PROJECT updated successfully.', new MarketingRecordResource($record));
    }
}
