<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Enums\ReportType;

class BookingReportSummaryController extends ReportSummaryController
{
    protected function reportType(): ReportType
    {
        return ReportType::Bookings;
    }
}
