<?php

namespace App\Contracts\Reports;

use App\Contracts\Reports\Generators\ReportGeneratorInterface;
use App\Contracts\Reports\Services\BookingReportServiceInterface;
use App\Contracts\Reports\Services\CustomerReportServiceInterface;
use App\Contracts\Reports\Services\InventoryReportServiceInterface;
use App\Contracts\Reports\Services\RevenueReportServiceInterface;
use App\Enums\DashboardDateFilter;
use App\Enums\ReportType;
use App\Exceptions\Reports\UnsupportedReportTypeException;
use Carbon\CarbonImmutable;

/**
 * Single entry point of the Reports module. Resolves the dedicated generator
 * for each report type (report APIs and exports) and exposes the read-only
 * report services. Controllers and actions must never reach report services,
 * generators, or repositories directly.
 */
interface ReportManagerInterface
{
    public function register(ReportGeneratorInterface $generator): void;

    /**
     * Resolve the dedicated generator for the given report type.
     *
     * @throws UnsupportedReportTypeException
     */
    public function generatorFor(ReportType|string $type): ReportGeneratorInterface;

    public function supports(ReportType|string $type): bool;

    /**
     * @return list<ReportGeneratorInterface>
     */
    public function generators(): array;

    /**
     * @return list<string>
     */
    public function registeredTypes(): array;

    public function revenueReports(): RevenueReportServiceInterface;

    public function bookingReports(): BookingReportServiceInterface;

    public function customerReports(): CustomerReportServiceInterface;

    public function inventoryReports(): InventoryReportServiceInterface;

    /**
     * Whether a report service produces a summary DTO for the given type
     * (payments map to the revenue report).
     */
    public function supportsSummary(ReportType|string $type): bool;

    /**
     * Generate the summary DTO for the given report type through its
     * dedicated report service.
     *
     * @throws UnsupportedReportTypeException
     */
    public function summaryFor(
        ReportType|string $type,
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): ReportDataInterface;
}
