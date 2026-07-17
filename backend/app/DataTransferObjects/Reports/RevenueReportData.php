<?php

namespace App\DataTransferObjects\Reports;

use App\Contracts\Reports\ReportDataInterface;

/**
 * Immutable revenue report payload. Totals originate from the existing
 * PaymentReportRepository, so revenue figures always match the dashboard.
 */
final readonly class RevenueReportData implements ReportDataInterface
{
    public function __construct(
        public float $totalRevenue,
        public int $totalPayments,
        public string $filter,
        public ?string $startDate,
        public ?string $endDate,
        public string $generatedAt,
    ) {}

    /**
     * @return array{total_revenue: float, total_payments: int, filter: string, start_date: ?string, end_date: ?string, generated_at: string}
     */
    public function toArray(): array
    {
        return [
            'total_revenue' => $this->totalRevenue,
            'total_payments' => $this->totalPayments,
            'filter' => $this->filter,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'generated_at' => $this->generatedAt,
        ];
    }

    /**
     * @return array{total_revenue: float, total_payments: int, filter: string, start_date: ?string, end_date: ?string, generated_at: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
