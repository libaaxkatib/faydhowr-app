<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Enums\ReportType;

class RevenueReportSummaryController extends ReportSummaryController
{
    /**
     * Revenue reports are produced from payment data; there is no separate
     * revenue report type.
     */
    protected function reportType(): ReportType
    {
        return ReportType::Payments;
    }
}
