<?php

namespace App\Events\Reports;

use App\Models\ReportExport;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportExportCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public ReportExport $reportExport) {}
}
