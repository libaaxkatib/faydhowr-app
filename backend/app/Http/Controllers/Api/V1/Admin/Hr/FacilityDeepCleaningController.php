<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\FacilityDeepCleaningState;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityCalendar;
use App\Support\Hr\Facility\FacilityDeepCleaningBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The monthly Deep Cleaning overview shown inside Facility Inspections: read-only, from recorded completions only. */
class FacilityDeepCleaningController extends Controller
{
    public function index(Request $request, FacilityDeepCleaningBuilder $builder): JsonResponse
    {
        $currentMonth = FacilityCalendar::currentMonth();

        $v = $request->validate([
            'month' => ['sometimes', 'nullable', 'date_format:Y-m', 'before_or_equal:'.$currentMonth],
            'state' => ['sometimes', 'nullable', Rule::in(FacilityDeepCleaningState::values())],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', Rule::in(['company', 'guri'])],
            'client_company_id' => ['sometimes', 'nullable', 'integer', 'exists:client_companies,id'],
            'include_inactive' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $result = $builder->build(null, $v);

        return ApiResponse::success('Monthly Deep Cleaning overview retrieved successfully.', $result['rows'], 200, [
            'counts' => $result['counts'],
            'month' => $result['month'],
            'current_month' => $currentMonth,
            // The calendar every month and date here is read in; the browser mirrors it (FACILITY_TIME_ZONE).
            'time_zone' => FacilityCalendar::TIMEZONE,
            'today' => FacilityCalendar::today()->toDateString(),
            'inactive_hidden' => $result['inactive_hidden'],
            'overdue_after_months' => FacilityDeepCleaningBuilder::OVERDUE_AFTER_MISSED_MONTHS,
            // Said plainly so a missing record is never read as a completion: only an explicit entry in an inspection counts.
            'basis' => 'Built only from Deep Cleaning completions recorded inside facility inspections. Nothing is assumed for months with no record.',
        ]);
    }
}
