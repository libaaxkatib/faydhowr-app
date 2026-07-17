<?php

namespace App\DataTransferObjects\Reports;

use App\Contracts\Reports\ReportDataInterface;

/**
 * Immutable booking report payload. Counts originate from the existing
 * BookingReportRepository summary; pending bookings are every booking that
 * has not reached a terminal state (completed or cancelled).
 */
final readonly class BookingReportData implements ReportDataInterface
{
    public function __construct(
        public int $totalBookings,
        public int $completedBookings,
        public int $cancelledBookings,
        public int $pendingBookings,
        public string $filter,
        public ?string $startDate,
        public ?string $endDate,
        public string $generatedAt,
    ) {}

    /**
     * @return array{total_bookings: int, completed_bookings: int, cancelled_bookings: int, pending_bookings: int, filter: string, start_date: ?string, end_date: ?string, generated_at: string}
     */
    public function toArray(): array
    {
        return [
            'total_bookings' => $this->totalBookings,
            'completed_bookings' => $this->completedBookings,
            'cancelled_bookings' => $this->cancelledBookings,
            'pending_bookings' => $this->pendingBookings,
            'filter' => $this->filter,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'generated_at' => $this->generatedAt,
        ];
    }

    /**
     * @return array{total_bookings: int, completed_bookings: int, cancelled_bookings: int, pending_bookings: int, filter: string, start_date: ?string, end_date: ?string, generated_at: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
