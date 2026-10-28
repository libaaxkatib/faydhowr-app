<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\MonthlyPaymentReportRequest;
use App\Models\ClientCompany;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\MonthlyPaymentReportBuilder;
use App\Support\Hr\Payroll\MonthlyPaymentReportExporter;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Downloads the monthly employee payment list as Excel: for all companies and guryo, or - with `scope` = company|guri and the record's id - for ONE
 * Company or ONE Guri only. Read-only: it never creates or changes a payroll run, a payment or an employee. The file holds employees' payment
 * numbers, so the route needs payroll access AND the dedicated permission that gates those numbers everywhere else. The audit entry records who
 * downloaded which month (and which record) and how many rows - never the numbers.
 */
class MonthlyPaymentReportController extends Controller
{
    /**
     * The Companies and the Guryo that actually exist, for the download's selectors. Real records only: nothing is listed that is not in the database,
     * and nothing is created to make a filter work.
     */
    public function filters(): JsonResponse
    {
        $records = ClientCompany::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'category', 'status']);
        $map = fn (string $category): array => $records
            ->filter(fn (ClientCompany $c): bool => ($c->category?->value ?? 'company') === $category)
            ->map(fn (ClientCompany $c): array => ['id' => $c->id, 'name' => $c->name, 'status' => $c->status?->value ?? (string) $c->status])
            ->values()
            ->all();

        return ApiResponse::success('Payment list filters retrieved successfully.', ['companies' => $map('company'), 'guryo' => $map('guri')]);
    }

    /**
     * What the download for the same month and selection would contain, as totals only: no employee, name or payment number is returned. It builds
     * the report with the very same builder call as the download, so the row count and the three totals are those of the workbook's "Payment list"
     * sheet. Read-only: nothing is stored and no audit entry is written (the audit entry belongs to the download itself).
     */
    public function preview(MonthlyPaymentReportRequest $request, MonthlyPaymentReportBuilder $builder): JsonResponse
    {
        $period = $request->validated('period');
        [$report, $company, $kind] = $this->reportFor($request, $builder);

        if ($company !== null && $report['rows'] === []) {
            return ApiResponse::error("{$kind} '{$company->name}' has no payment records for {$period}.", 'NO_PAYMENT_RECORDS', 422);
        }

        $summary = $report['summary'];
        $temporaryCount = (int) ($summary['temporary_cover_count'] ?? 0);

        return ApiResponse::success('Payment list preview retrieved successfully.', [
            'period' => $period,
            'scope' => $company === null ? 'all' : strtolower($kind),
            'record' => $company === null ? null : ['id' => $company->id, 'name' => $company->name, 'kind' => strtolower($kind)],
            'is_estimate' => (bool) $report['is_estimate'],
            'row_count' => count($report['rows']),
            'permanent_count' => (int) $summary['employee_count'],
            'temporary_count' => $temporaryCount,
            'permanent_total' => PayrollMoney::format((int) $summary['total_net_cents']),
            'temporary_total' => PayrollMoney::format((int) ($summary['temporary_total_cents'] ?? 0)),
            'combined_total' => PayrollMoney::format((int) ($summary['total_with_temporary_cents'] ?? $summary['total_net_cents'])),
            // The same rule as the workbook's blank, highlighted NUMBER cells (every row of the Payment list sheet, Temporary rows included).
            'missing_payment_number_count' => count(array_filter($report['rows'], fn (array $row): bool => $row['payment_account'] === null)),
            'currency' => 'USD',
        ]);
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?ClientCompany, 2: ?string} the report, the selected record (null = all) and its kind label
     */
    private function reportFor(MonthlyPaymentReportRequest $request, MonthlyPaymentReportBuilder $builder): array
    {
        $company = $request->filterCompanyId() !== null ? ClientCompany::query()->findOrFail($request->filterCompanyId()) : null;
        $report = $builder->build($request->validated('period'), $company?->id);
        $report['company_name'] = $company?->name;
        $kind = $company === null ? null : (($company->category?->value ?? 'company') === 'guri' ? 'Guri' : 'Company');
        $report['filter_label'] = $company === null ? 'All companies and guryo' : "{$kind}: {$company->name}";

        return [$report, $company, $kind];
    }

    public function download(MonthlyPaymentReportRequest $request, MonthlyPaymentReportBuilder $builder, MonthlyPaymentReportExporter $exporter): BinaryFileResponse|JsonResponse
    {
        $period = $request->validated('period');
        [$report, $company, $kind] = $this->reportFor($request, $builder);

        // A list for one Company or Guri with nothing to pay says so, instead of handing over a workbook of zeros.
        if ($company !== null && $report['rows'] === []) {
            return ApiResponse::error("{$kind} '{$company->name}' has no payment records for {$period}. Nothing was downloaded.", 'NO_PAYMENT_RECORDS', 422);
        }

        $path = tempnam(sys_get_temp_dir(), 'payment-list').'.xlsx';
        $exporter->write($report, $path);

        $rows = $report['summary']['employee_count'] + ($report['summary']['temporary_cover_count'] ?? 0);

        event(AuditEvent::record(
            action: AuditAction::Export,
            admin: $request->user(),
            description: $company === null
                ? "Downloaded the monthly payment list for {$period} ({$report['summary']['employee_count']} employees)."
                : "Downloaded the monthly payment list for {$company->name}, {$period} ({$report['summary']['employee_count']} employees).",
            entityType: $company === null ? null : ClientCompany::class,
            entityId: $company?->id,
            metadata: ['period' => $period, 'employee_count' => $report['summary']['employee_count'], 'temporary_cover_count' => $report['summary']['temporary_cover_count'] ?? 0, 'rows' => $rows, 'source' => $report['source'], 'client_company_id' => $company?->id, 'scope' => $company === null ? 'all' : strtolower($kind)],
        ));

        $suffix = $company === null ? '' : '-'.(Str::slug($company->name) ?: 'company-'.$company->id);
        $response = response()->download($path, "payment-list-{$period}{$suffix}.xlsx")->deleteFileAfterSend(true);
        // File responses are public by default; this file holds payment numbers, so it must be private and never stored.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
