<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\MonthlyPaymentReportRequest;
use App\Models\ClientCompany;
use App\Support\Hr\Payroll\MonthlyPaymentReportBuilder;
use App\Support\Hr\Payroll\MonthlyPaymentReportExporter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads the monthly employee payment list as Excel, for all companies or - with client_company_id - for one company only.
 * Read-only: it never creates or changes a payroll run, a payment or an employee. The file holds employees' payment/account numbers, so
 * the route needs payroll access AND the dedicated permission that gates those numbers everywhere else. The audit entry records who
 * downloaded which month (and which company) and how many rows - never the numbers.
 */
class MonthlyPaymentReportController extends Controller
{
    public function download(MonthlyPaymentReportRequest $request, MonthlyPaymentReportBuilder $builder, MonthlyPaymentReportExporter $exporter): BinaryFileResponse
    {
        $period = $request->validated('period');
        $companyId = $request->validated('client_company_id');
        $company = $companyId !== null ? ClientCompany::query()->findOrFail((int) $companyId) : null;
        $report = $builder->build($period, $company?->id);
        $report['company_name'] = $company?->name;

        $path = tempnam(sys_get_temp_dir(), 'payment-list').'.xlsx';
        $exporter->write($report, $path);

        event(AuditEvent::record(
            action: AuditAction::Export,
            admin: $request->user(),
            description: $company === null
                ? "Downloaded the monthly payment list for {$period} ({$report['summary']['employee_count']} employees)."
                : "Downloaded the monthly payment list for {$company->name}, {$period} ({$report['summary']['employee_count']} employees).",
            entityType: $company === null ? null : ClientCompany::class,
            entityId: $company?->id,
            metadata: ['period' => $period, 'employee_count' => $report['summary']['employee_count'], 'source' => $report['source'], 'client_company_id' => $company?->id],
        ));

        $suffix = $company === null ? '' : '-'.(Str::slug($company->name) ?: 'company-'.$company->id);
        $response = response()->download($path, "payment-list-{$period}{$suffix}.xlsx")->deleteFileAfterSend(true);
        // File responses are public by default; this file holds payment numbers, so it must be private and never stored.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
