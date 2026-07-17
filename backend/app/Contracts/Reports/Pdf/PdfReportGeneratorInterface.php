<?php

namespace App\Contracts\Reports\Pdf;

use App\Contracts\Reports\ReportDataInterface;

/**
 * Renders report DTOs into PDF documents. Implementations must consume
 * report DTOs only: no repository access, no business logic, and no
 * calculations.
 */
interface PdfReportGeneratorInterface
{
    /**
     * Render the given report DTO into a PDF document and return the raw
     * PDF bytes.
     */
    public function generate(ReportDataInterface $report): string;
}
