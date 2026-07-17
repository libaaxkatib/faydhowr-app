<?php

namespace App\Services\Reports\Services;

use App\Contracts\Reports\Services\RevenueReportServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Reports\RevenueReportData;
use App\Enums\DashboardDateFilter;
use App\Repositories\Reports\PaymentReportRepository;
use Carbon\CarbonImmutable;

/**
 * Read-only revenue report. All figures come from the existing
 * PaymentReportRepository summary and the shared DashboardDateFilter range
 * resolution, so this report and the dashboard always agree; no revenue
 * calculation or filter logic is duplicated here.
 */
class RevenueReportService implements RevenueReportServiceInterface
{
    public function __construct(private PaymentReportRepository $paymentRepository) {}

    public function generate(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): RevenueReportData {
        [$dateFrom, $dateTo] = $filter?->dateRange($startDate, $endDate) ?? [null, null];

        $summary = $this->paymentRepository->summary(new NormalizedReportFilters(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        ));

        return new RevenueReportData(
            totalRevenue: (float) $summary['total_amount'],
            totalPayments: (int) $summary['total_records'],
            filter: $filter?->value ?? 'all_time',
            startDate: $dateFrom?->toIso8601String(),
            endDate: $dateTo?->toIso8601String(),
            generatedAt: CarbonImmutable::now()->toIso8601String(),
        );
    }
}
