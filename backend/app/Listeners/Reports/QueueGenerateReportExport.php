<?php

namespace App\Listeners\Reports;

use App\Events\Reports\ReportExportRequested;
use App\Jobs\Reports\GenerateReportExportJob;

class QueueGenerateReportExport
{
    public function handle(ReportExportRequested $event): void
    {
        GenerateReportExportJob::dispatch($event->reportExport->id);
    }
}
