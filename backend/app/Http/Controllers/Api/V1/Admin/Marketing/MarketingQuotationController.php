<?php

namespace App\Http\Controllers\Api\V1\Admin\Marketing;

use App\Actions\Marketing\CreateMarketingQuotationAction;
use App\Actions\Marketing\UpdateMarketingQuotationStatusAction;
use App\Enums\Marketing\MarketingQuotationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Marketing\StoreMarketingQuotationRequest;
use App\Http\Requests\Api\V1\Admin\Marketing\UpdateMarketingQuotationStatusRequest;
use App\Http\Resources\Api\V1\Admin\Marketing\MarketingQuotationResource;
use App\Models\MarketingQuotation;
use App\Models\MarketingRecord;
use App\Support\ApiResponse;
use App\Support\Marketing\MarketingScope;
use Illuminate\Http\JsonResponse;

class MarketingQuotationController extends Controller
{
    public function store(StoreMarketingQuotationRequest $request, MarketingRecord $record, CreateMarketingQuotationAction $action): JsonResponse
    {
        MarketingScope::authorizeRecord($record, $request->user());
        $quotation = $action->handle($record, $request->validated(), $request->user());

        return ApiResponse::success('Quotation added successfully.', new MarketingQuotationResource($quotation), 201);
    }

    public function updateStatus(UpdateMarketingQuotationStatusRequest $request, MarketingQuotation $quotation, UpdateMarketingQuotationStatusAction $action): JsonResponse
    {
        MarketingScope::authorizeQuotation($quotation, $request->user());
        $quotation = $action->handle($quotation, MarketingQuotationStatus::from($request->validated('status')), $request->user());

        return ApiResponse::success('Quotation status updated successfully.', new MarketingQuotationResource($quotation));
    }
}
