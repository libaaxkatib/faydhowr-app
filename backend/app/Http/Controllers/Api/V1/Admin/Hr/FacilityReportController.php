<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\FacilityComplaintType;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Facility inspection and complaint reports: real stored data, filtered; read-only. */
class FacilityReportController extends Controller
{
    public function show(Request $request, FacilityReportBuilder $builder): JsonResponse
    {
        $v = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'client_company_id' => ['sometimes', 'nullable', 'integer', 'exists:client_companies,id'],
            'type' => ['sometimes', 'nullable', Rule::in(FacilityComplaintType::values())],
        ]);

        return ApiResponse::success('Facility report retrieved successfully.', $builder->build(null, $v));
    }
}
