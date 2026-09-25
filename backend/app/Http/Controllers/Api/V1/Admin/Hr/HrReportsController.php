<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\GetHrReportsSummaryAction;
use App\Actions\Hr\GetPayrollRollupAction;
use App\Actions\Hr\ListEmployeeLeavesAction;
use App\Actions\Hr\ListEmployeePerformanceReviewsAction;
use App\Actions\Hr\ListFinancialLedgerAction;
use App\Actions\Hr\ListTemporaryReplacementHistoryAction;
use App\Actions\Hr\ListWaitingRosterAction;
use App\Actions\Hr\ListWorkforceRequestHistoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\GetHrReportsSummaryRequest;
use App\Http\Requests\Api\V1\Admin\Hr\GetPayrollRollupRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ListEmployeeLeavesRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ListEmployeePerformanceReviewsRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ListFinancialLedgerRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ListTemporaryReplacementHistoryRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ListWaitingRosterRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ListWorkforceRequestHistoryRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeLeaveResource;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeePerformanceReviewResource;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Http\Resources\Api\V1\Admin\Hr\TemporaryReplacementResource;
use App\Http\Resources\Api\V1\Admin\Hr\WorkforceRequestResource;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class HrReportsController extends Controller
{
    public function summary(GetHrReportsSummaryRequest $request, GetHrReportsSummaryAction $action): JsonResponse
    {
        $summary = $action->handle(
            $request->validated('from'),
            $request->validated('to'),
            $request->only(['client_company_id', 'work_location_id', 'assignment_status']),
        );

        return ApiResponse::success('HR report summary retrieved successfully.', $summary);
    }

    public function waitingRoster(ListWaitingRosterRequest $request, ListWaitingRosterAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated());

        return ApiResponse::success(
            'Waiting roster retrieved successfully.',
            EmployeeResource::collection($paginator->items()),
            200,
            $this->paginationMeta($paginator),
        );
    }

    public function workforceRequestHistory(ListWorkforceRequestHistoryRequest $request, ListWorkforceRequestHistoryAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated());

        return ApiResponse::success(
            'Workforce request history retrieved successfully.',
            WorkforceRequestResource::collection($paginator->items()),
            200,
            $this->paginationMeta($paginator),
        );
    }

    public function temporaryReplacementHistory(ListTemporaryReplacementHistoryRequest $request, ListTemporaryReplacementHistoryAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated());

        return ApiResponse::success(
            'Temporary replacement history retrieved successfully.',
            TemporaryReplacementResource::collection($paginator->items()),
            200,
            $this->paginationMeta($paginator),
        );
    }

    public function leaves(ListEmployeeLeavesRequest $request, ListEmployeeLeavesAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated());

        return ApiResponse::success(
            'Leave report retrieved successfully.',
            EmployeeLeaveResource::collection($paginator->items()),
            200,
            $this->paginationMeta($paginator),
        );
    }

    public function performanceReviews(ListEmployeePerformanceReviewsRequest $request, ListEmployeePerformanceReviewsAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated());

        return ApiResponse::success(
            'Performance report retrieved successfully.',
            EmployeePerformanceReviewResource::collection($paginator->items()),
            200,
            $this->paginationMeta($paginator),
        );
    }

    public function financialLedger(ListFinancialLedgerRequest $request, ListFinancialLedgerAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated());

        return ApiResponse::success(
            'Financial ledger retrieved successfully.',
            $paginator->items(),
            200,
            $this->paginationMeta($paginator),
        );
    }

    public function payrollRollup(GetPayrollRollupRequest $request, GetPayrollRollupAction $action): JsonResponse
    {
        $rollup = $action->handle(
            $request->validated('period'),
            $request->only(['client_company_id', 'department_id']),
        );

        return ApiResponse::success('Payroll rollup retrieved successfully.', $rollup);
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
