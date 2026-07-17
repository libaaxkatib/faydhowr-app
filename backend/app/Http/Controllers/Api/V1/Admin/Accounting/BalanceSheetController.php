<?php

namespace App\Http\Controllers\Api\V1\Admin\Accounting;

use App\Http\Requests\Api\V1\Admin\Accounting\AccountingReportRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class BalanceSheetController extends AccountingReportController
{
    public function show(AccountingReportRequest $request): JsonResponse
    {
        $this->authorizeReport();

        $period = $this->resolvePeriod($request);

        $sheet = $period !== null
            ? $this->accounting->financialReports()->balanceSheetForPeriod($period)
            : $this->accounting->financialReports()->balanceSheet($request->startDate(), $request->endDate());

        return ApiResponse::success(
            'Balance sheet generated successfully.',
            $sheet->toArray(),
        );
    }
}
