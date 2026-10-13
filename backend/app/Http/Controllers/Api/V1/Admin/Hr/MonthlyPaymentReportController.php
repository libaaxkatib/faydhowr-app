<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\MonthlyPaymentReportRequest;
use App\Support\Hr\Payroll\MonthlyPaymentReportBuilder;
use App\Support\Hr\Payroll\MonthlyPaymentReportExporter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads the monthly employee payment list as Excel. Read-only: it never creates or changes a payroll run,
 * a payment or a payroll line, and downloading it does not mark anything paid. The file contains employees'
 * payment/account numbers, so the route needs BOTH hr.payroll.view and hr.manage (the permission that already
 * gates those numbers everywhere else). The audit entry records who downloaded which month and how many rows,
 * never any number.
 */
class MonthlyPaymentReportController extends Controller
{
    public function download(MonthlyPaymentReportRequest $request, MonthlyPaymentReportBuilder $builder, MonthlyPaymentReportExporter $exporter): BinaryFileResponse
    {
        $period = $request->validated('period');
        $report = $builder->build($period);

        $path = tempnam(sys_get_temp_dir(), 'payment-list').'.xlsx';
        $exporter->write($report, $path);

        event(AuditEvent::record(
            action: AuditAction::Export,
            admin: $request->user(),
            description: "Downloaded the monthly payment list for {$period} ({$report['summary']['employee_count']} employees).",
            entityType: null,
            entityId: null,
            metadata: ['period' => $period, 'employee_count' => $report['summary']['employee_count'], 'source' => $report['source']],
        ));

        $response = response()->download($path, "payment-list-{$period}.xlsx")->deleteFileAfterSend(true);
        // File responses are public by default; this file holds payment numbers, so it must be private and never stored.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
