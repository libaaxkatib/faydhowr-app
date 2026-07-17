<?php

namespace App\Contracts\Reports\Excel;

use App\Contracts\Reports\ReportDataInterface;

/**
 * Renders report DTOs into Excel workbooks. Implementations must consume
 * report DTOs only: no repository access, no business logic, and no
 * calculations.
 */
interface ExcelReportGeneratorInterface
{
    /**
     * Render the given report DTO into a single-worksheet Excel workbook
     * and return the raw XLSX bytes.
     */
    public function generate(ReportDataInterface $report): string;
}
