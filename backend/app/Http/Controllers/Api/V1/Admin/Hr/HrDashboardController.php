<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\GetHrDashboardAction;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\AdminPermissionResolver;
use App\Support\ApiResponse;
use App\Support\Hr\Facility\FacilityCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HrDashboardController extends Controller
{
    /**
     * The dashboard. The payroll KPIs (owner decisions C-7, D-5) are company-level, cover ONE payroll month (default: the previous month, optional
     * `payroll_period` = YYYY-MM) and are included only for a user who also holds hr.payroll.view - the server decides, not the screen.
     */
    public function show(Request $request, GetHrDashboardAction $action, AdminPermissionResolver $resolver): JsonResponse
    {
        $validator = Validator::make($request->query(), ['payroll_period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $user = $request->user();
        $includePayroll = $user instanceof Admin && $resolver->has($user, 'hr.payroll.view', $request);
        $period = $request->query('payroll_period') ?: FacilityCalendar::today()->startOfMonth()->subMonth()->format('Y-m');

        return ApiResponse::success('HR dashboard retrieved successfully.', $action->handle($includePayroll ? $period : null));
    }
}
