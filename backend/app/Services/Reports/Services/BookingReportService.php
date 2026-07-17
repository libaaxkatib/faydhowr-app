<?php

namespace App\Services\Reports\Services;

use App\Contracts\Reports\Services\BookingReportServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Reports\BookingReportData;
use App\Enums\BookingStatus;
use App\Enums\DashboardDateFilter;
use App\Repositories\Reports\BookingReportRepository;
use Carbon\CarbonImmutable;

/**
 * Read-only booking report. All counts come from the existing
 * BookingReportRepository summary combined with its status filter, and the
 * shared DashboardDateFilter range resolution; no query or filter logic is
 * duplicated here. Pending bookings are derived as every booking that has
 * not reached a terminal state (completed or cancelled).
 */
class BookingReportService implements BookingReportServiceInterface
{
    public function __construct(private BookingReportRepository $bookingRepository) {}

    public function generate(
        ?DashboardDateFilter $filter = null,
        ?CarbonImmutable $startDate = null,
        ?CarbonImmutable $endDate = null,
    ): BookingReportData {
        [$dateFrom, $dateTo] = $filter?->dateRange($startDate, $endDate) ?? [null, null];

        $total = $this->countBookings($dateFrom, $dateTo);
        $completed = $this->countBookings($dateFrom, $dateTo, BookingStatus::Completed);
        $cancelled = $this->countBookings($dateFrom, $dateTo, BookingStatus::Cancelled);

        return new BookingReportData(
            totalBookings: $total,
            completedBookings: $completed,
            cancelledBookings: $cancelled,
            pendingBookings: $total - $completed - $cancelled,
            filter: $filter?->value ?? 'all_time',
            startDate: $dateFrom?->toIso8601String(),
            endDate: $dateTo?->toIso8601String(),
            generatedAt: CarbonImmutable::now()->toIso8601String(),
        );
    }

    private function countBookings(
        ?CarbonImmutable $dateFrom,
        ?CarbonImmutable $dateTo,
        ?BookingStatus $status = null,
    ): int {
        $summary = $this->bookingRepository->summary(new NormalizedReportFilters(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            status: $status?->value,
        ));

        return (int) $summary['total_records'];
    }
}
