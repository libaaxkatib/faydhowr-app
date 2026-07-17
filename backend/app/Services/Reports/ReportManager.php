<?php

namespace App\Services\Reports;

use App\Contracts\Reports\Generators\ReportGeneratorInterface;
use App\Contracts\Reports\ReportDataInterface;
use App\Contracts\Reports\ReportManagerInterface;
use App\Contracts\Reports\Services\BookingReportServiceInterface;
use App\Contracts\Reports\Services\CustomerReportServiceInterface;
use App\Contracts\Reports\Services\InventoryReportServiceInterface;
use App\Contracts\Reports\Services\RevenueReportServiceInterface;
use App\Enums\DashboardDateFilter;
use App\Enums\ReportType;
use App\Exceptions\Reports\UnsupportedReportTypeException;
use Carbon\CarbonImmutable;

class ReportManager implements ReportManagerInterface
{
    /**
     * @var list<ReportGeneratorInterface>
     */
    private array $generators = [];

    /**
     * @param  iterable<ReportGeneratorInterface>  $generators
     */
    public function __construct(
        private RevenueReportServiceInterface $revenueReportService,
        private BookingReportServiceInterface $bookingReportService,
        private CustomerReportServiceInterface $customerReportService,
        private InventoryReportServiceInterface $inventoryReportService,
        iterable $generators = [],
    ) {
        foreach ($generators as $generator) {
            $this->register($generator);
        }
    }

    public function register(ReportGeneratorInterface $generator): void
    {
        $this->generators[] = $generator;
    }

    public function generatorFor(ReportType|string $type): ReportGeneratorInterface
    {
        $reportType = $this->resolveType($type);

        foreach ($this->generators as $generator) {
            if ($generator->supports($reportType)) {
                return $generator;
            }
        }

        throw UnsupportedReportTypeException::forType($reportType);
    }

    public function supports(ReportType|string $type): bool
    {
        $reportType = $type instanceof ReportType ? $type : ReportType::tryFrom($type);

        if ($reportType === null) {
            return false;
        }

        foreach ($this->generators as $generator) {
            if ($generator->supports($reportType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ReportGeneratorInterface>
     */
    public function generators(): array
    {
        return $this->generators;
    }

    /**
     * @return list<string>
     */
    public function registeredTypes(): array
    {
        $types = [];

        foreach (ReportType::cases() as $type) {
            if ($this->supports($type)) {
                $types[] = $type->value;
            }
        }

        return $types;
    }

    public function revenueReports(): RevenueReportServiceInterface
    {
        return $this->revenueReportService;
    }

    public function bookingReports(): BookingReportServiceInterface
    {
        return $this->bookingReportService;
    }

    public function customerReports(): CustomerReportServiceInterface
    {
        return $this->customerReportService;
    }

    public function inventoryReports(): InventoryReportServiceInterface
    {
        return $this->inventoryReportService;
    }

    public function supportsSummary(ReportType|string $type): bool
    {
        $reportType = $type instanceof ReportType ? $type : ReportType::tryFrom($type);

        return in_array($reportType, [
            ReportType::Payments,
            ReportType::Bookings,
            ReportType::Customers,
            ReportType::Inventory,
        ], true);
    }

    public function summaryFor(
        ReportType|string $type,
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): ReportDataInterface {
        return match ($this->resolveType($type)) {
            ReportType::Payments => $this->revenueReportService->generate($filter, $startDate, $endDate),
            ReportType::Bookings => $this->bookingReportService->generate($filter, $startDate, $endDate),
            ReportType::Customers => $this->customerReportService->generate($filter, $startDate, $endDate),
            ReportType::Inventory => $this->inventoryReportService->generate($filter, $startDate, $endDate),
            default => throw UnsupportedReportTypeException::forType($type),
        };
    }

    private function resolveType(ReportType|string $type): ReportType
    {
        if ($type instanceof ReportType) {
            return $type;
        }

        return ReportType::tryFrom($type) ?? throw UnsupportedReportTypeException::forType($type);
    }
}
