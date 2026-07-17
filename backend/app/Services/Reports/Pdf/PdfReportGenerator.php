<?php

namespace App\Services\Reports\Pdf;

use App\Contracts\Reports\Pdf\PdfReportGeneratorInterface;
use App\Contracts\Reports\ReportDataInterface;
use App\Support\Reports\ReportDataPresenter;
use Barryvdh\DomPDF\PDF;
use Illuminate\Contracts\Container\Container;

/**
 * Presentation-only PDF renderer for report DTOs. The document content is
 * derived entirely from the DTO's serialized payload, so an identical DTO
 * always produces identical PDF content. No repositories, business logic,
 * or calculations are involved.
 */
class PdfReportGenerator implements PdfReportGeneratorInterface
{
    /**
     * The dompdf wrapper is stateful, so a fresh instance is resolved from
     * the container for every generated document.
     */
    public function __construct(private Container $container) {}

    public function generate(ReportDataInterface $report): string
    {
        $payload = $report->toArray();

        /** @var PDF $pdf */
        $pdf = $this->container->make(PDF::class);

        return $pdf
            ->loadView('reports.pdf.report', [
                'companyName' => (string) config('app.name'),
                'reportName' => ReportDataPresenter::reportName($report),
                'generatedAt' => $payload['generated_at'] ?? null,
                'filter' => $payload['filter'] ?? null,
                'startDate' => $payload['start_date'] ?? null,
                'endDate' => $payload['end_date'] ?? null,
                'metrics' => ReportDataPresenter::metrics($payload),
            ])
            ->output();
    }
}
