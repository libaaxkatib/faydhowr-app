<?php

namespace App\Jobs\Reports;

use App\Services\Reports\ReportExportManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateReportExportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reportExportId) {}

    public function handle(ReportExportManager $reportExportManager): void
    {
        $reportExportManager->process($this->reportExportId);
    }
}
