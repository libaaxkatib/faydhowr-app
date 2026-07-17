<?php

namespace App\DataTransferObjects\Reports;

use App\Contracts\Reports\ReportDataInterface;

/**
 * Immutable customer report payload. Counts originate from the existing
 * CustomerReportRepository summary: totals and classification counts are
 * measured against the customer base as of the end of the selected range,
 * while new customers are those created within the range.
 */
final readonly class CustomerReportData implements ReportDataInterface
{
    public function __construct(
        public int $totalCustomers,
        public int $activeCustomers,
        public int $inactiveCustomers,
        public int $newCustomers,
        public string $filter,
        public ?string $startDate,
        public ?string $endDate,
        public string $generatedAt,
    ) {}

    /**
     * @return array{total_customers: int, active_customers: int, inactive_customers: int, new_customers: int, filter: string, start_date: ?string, end_date: ?string, generated_at: string}
     */
    public function toArray(): array
    {
        return [
            'total_customers' => $this->totalCustomers,
            'active_customers' => $this->activeCustomers,
            'inactive_customers' => $this->inactiveCustomers,
            'new_customers' => $this->newCustomers,
            'filter' => $this->filter,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'generated_at' => $this->generatedAt,
        ];
    }

    /**
     * @return array{total_customers: int, active_customers: int, inactive_customers: int, new_customers: int, filter: string, start_date: ?string, end_date: ?string, generated_at: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
