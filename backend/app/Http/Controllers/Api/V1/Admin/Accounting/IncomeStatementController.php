<?php

namespace App\Http\Controllers\Api\V1\Admin\Accounting;

use App\Http\Requests\Api\V1\Admin\Accounting\AccountingReportRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class IncomeStatementController extends AccountingReportController
{
    public function show(AccountingReportRequest $request): JsonResponse
    {
        $this->authorizeReport();

        $period = $this->resolvePeriod($request);

        $statement = $period !== null
            ? $this->accounting->financialReports()->incomeStatementForPeriod($period)
            : $this->accounting->financialReports()->incomeStatement($request->startDate(), $request->endDate());

        return ApiResponse::success(
            'Income statement generated successfully.',
            $statement->toArray(),
        );
    }
}
