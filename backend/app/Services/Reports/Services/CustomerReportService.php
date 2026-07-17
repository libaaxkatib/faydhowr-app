<?php

namespace App\Services\Reports\Services;

use App\Contracts\Reports\Services\CustomerReportServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Reports\CustomerReportData;
use App\Enums\DashboardDateFilter;
use App\Repositories\Reports\CustomerReportRepository;
use Carbon\CarbonImmutable;

/**
 * Read-only customer report. All counts come from the existing
 * CustomerReportRepository summary combined with its filters, and the shared
 * DashboardDateFilter range resolution; no query or filter logic is
 * duplicated here. Totals and classification counts reflect the customer
 * base as of the end of the selected range, while new customers are those
 * created within the range.
 */
class CustomerReportService implements CustomerReportServiceInterface
{
    private const CLASSIFICATION_ACTIVE = 'active_customer';

    private const CLASSIFICATION_LEAD = 'lead';

    public function __construct(private CustomerReportRepository $customerRepository) {}

    public function generate(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): CustomerReportData {
        [$dateFrom, $dateTo] = $filter?->dateRange($startDate, $endDate) ?? [null, null];

        return new CustomerReportData(
            totalCustomers: $this->countCustomers(dateTo: $dateTo),
            activeCustomers: $this->countCustomers(dateTo: $dateTo, classification: self::CLASSIFICATION_ACTIVE),
            inactiveCustomers: $this->countCustomers(dateTo: $dateTo, classification: self::CLASSIFICATION_LEAD),
            newCustomers: $this->countCustomers(dateFrom: $dateFrom, dateTo: $dateTo),
            filter: $filter?->value ?? 'all_time',
            startDate: $dateFrom?->toIso8601String(),
            endDate: $dateTo?->toIso8601String(),
            generatedAt: CarbonImmutable::now()->toIso8601String(),
        );
    }

    private function countCustomers(
        ?CarbonImmutable $dateFrom = null,
        ?CarbonImmutable $dateTo = null,
        ?string $classification = null,
    ): int {
        $summary = $this->customerRepository->summary(new NormalizedReportFilters(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            status: $classification,
        ));

        return (int) $summary['total_records'];
    }
}
