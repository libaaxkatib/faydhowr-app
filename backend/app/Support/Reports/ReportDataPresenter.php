<?php

namespace App\Support\Reports;

use App\Contracts\Reports\ReportDataInterface;
use Illuminate\Support\Str;

/**
 * Shared presentation mapping for report DTO exports (PDF, Excel). Purely
 * derives labels from the DTO; no business logic or calculations.
 */
final class ReportDataPresenter
{
    /**
     * DTO keys rendered as report context (header and filter sections)
     * rather than metric rows.
     */
    private const CONTEXT_KEYS = ['filter', 'start_date', 'end_date', 'generated_at'];

    /**
     * Derive a human-readable report name from the DTO class, e.g.
     * RevenueReportData becomes "Revenue Report".
     */
    public static function reportName(ReportDataInterface $report): string
    {
        return Str::of(class_basename($report))
            ->headline()
            ->beforeLast(' Data')
            ->toString();
    }

    /**
     * Derive the download filename for an exported report, e.g. the revenue
     * DTO with a pdf extension becomes "revenue-report.pdf".
     */
    public static function downloadFilename(ReportDataInterface $report, string $extension): string
    {
        return Str::slug(self::reportName($report)).'.'.$extension;
    }

    /**
     * Every non-context DTO value as a labelled metric row, e.g.
     * total_revenue becomes "Total Revenue".
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function metrics(array $payload): array
    {
        $metrics = [];

        foreach (array_diff_key($payload, array_flip(self::CONTEXT_KEYS)) as $key => $value) {
            $metrics[Str::headline($key)] = $value;
        }

        return $metrics;
    }
}
