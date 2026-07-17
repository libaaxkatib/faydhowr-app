<?php

namespace App\Actions\Report;

use App\Contracts\Reports\ReportDataInterface;
use App\Contracts\Reports\ReportManagerInterface;
use App\Enums\DashboardDateFilter;
use App\Enums\ReportType;
use App\Exceptions\Reports\UnsupportedReportTypeException;
use Carbon\CarbonImmutable;

class GetReportSummaryAction
{
    public function __construct(private ReportManagerInterface $reportManager) {}

    /**
     * Generate the summary report DTO for the given report type through the
     * report manager, which remains the single entry point of the module.
     *
     * @throws UnsupportedReportTypeException
     */
    public function handle(
        ReportType $type,
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): ReportDataInterface {
        return $this->reportManager->summaryFor($type, $filter, $startDate, $endDate);
    }
}
