<?php

namespace App\Http\Controllers\Api\V1\Admin\Reports;

use App\Actions\Report\GetReportSummaryAction;
use App\Contracts\Reports\Excel\ExcelReportGeneratorInterface;
use App\Contracts\Reports\Pdf\PdfReportGeneratorInterface;
use App\Contracts\Reports\ReportDataInterface;
use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\GetReportSummaryRequest;
use App\Support\ApiResponse;
use App\Support\Reports\ReportDataPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Throwable;

/**
 * Shared behavior for the dedicated report summary endpoints: JSON returns
 * the report DTO data, while the PDF and Excel variants stream the rendered
 * exports. Subclasses only declare their report type; all report generation
 * flows through the action into the report manager. Generation failures
 * return the module's standard REPORT_GENERATION_FAILED error.
 */
abstract class ReportSummaryController extends Controller
{
    public function __construct(private GetReportSummaryAction $reportSummary) {}

    abstract protected function reportType(): ReportType;

    public function show(GetReportSummaryRequest $request): JsonResponse
    {
        try {
            $summary = $this->summary($request);
        } catch (Throwable $exception) {
            return $this->generationFailed($exception);
        }

        return ApiResponse::success('Report generated successfully.', $summary->toArray());
    }

    public function pdf(GetReportSummaryRequest $request, PdfReportGeneratorInterface $generator): Response|JsonResponse
    {
        try {
            $summary = $this->summary($request);
            $document = $generator->generate($summary);
        } catch (Throwable $exception) {
            return $this->generationFailed($exception);
        }

        return $this->downloadResponse(
            $document,
            'application/pdf',
            ReportDataPresenter::downloadFilename($summary, 'pdf'),
        );
    }

    public function excel(GetReportSummaryRequest $request, ExcelReportGeneratorInterface $generator): Response|JsonResponse
    {
        try {
            $summary = $this->summary($request);
            $document = $generator->generate($summary);
        } catch (Throwable $exception) {
            return $this->generationFailed($exception);
        }

        return $this->downloadResponse(
            $document,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ReportDataPresenter::downloadFilename($summary, 'xlsx'),
        );
    }

    private function generationFailed(Throwable $exception): JsonResponse
    {
        report($exception);

        return ApiResponse::error('Failed to generate report.', 'REPORT_GENERATION_FAILED', 500);
    }

    private function summary(GetReportSummaryRequest $request): ReportDataInterface
    {
        return $this->reportSummary->handle(
            $this->reportType(),
            $request->dateFilter(),
            $request->startDate(),
            $request->endDate(),
        );
    }

    private function downloadResponse(string $contents, string $contentType, string $filename): Response
    {
        return response($contents, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
