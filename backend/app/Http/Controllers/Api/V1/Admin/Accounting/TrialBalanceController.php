<?php

namespace App\Http\Controllers\Api\V1\Admin\Accounting;

use App\Http\Requests\Api\V1\Admin\Accounting\AccountingReportRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TrialBalanceController extends AccountingReportController
{
    public function show(AccountingReportRequest $request): JsonResponse
    {
        $this->authorizeReport();

        $period = $this->resolvePeriod($request);

        $trialBalance = $period !== null
            ? $this->accounting->trialBalance()->generateForPeriod($period)
            : $this->accounting->trialBalance()->generate($request->startDate(), $request->endDate());

        return ApiResponse::success(
            'Trial balance generated successfully.',
            $trialBalance->toArray(),
        );
    }
}
