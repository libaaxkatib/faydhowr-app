<?php

namespace App\DataTransferObjects\Reports;

use App\Contracts\Reports\ReportDataInterface;

/**
 * Immutable inventory report payload. Counts originate from the existing
 * InventoryReportRepository. Stock buckets are mutually exclusive and sum to
 * the total: out of stock (no stock), low stock (at or below the product's
 * low stock threshold), and in stock (above the threshold).
 */
final readonly class InventoryReportData implements ReportDataInterface
{
    public function __construct(
        public int $totalProducts,
        public int $inStockProducts,
        public int $lowStockProducts,
        public int $outOfStockProducts,
        public string $filter,
        public ?string $startDate,
        public ?string $endDate,
        public string $generatedAt,
    ) {}

    /**
     * @return array{total_products: int, in_stock_products: int, low_stock_products: int, out_of_stock_products: int, filter: string, start_date: ?string, end_date: ?string, generated_at: string}
     */
    public function toArray(): array
    {
        return [
            'total_products' => $this->totalProducts,
            'in_stock_products' => $this->inStockProducts,
            'low_stock_products' => $this->lowStockProducts,
            'out_of_stock_products' => $this->outOfStockProducts,
            'filter' => $this->filter,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'generated_at' => $this->generatedAt,
        ];
    }

    /**
     * @return array{total_products: int, in_stock_products: int, low_stock_products: int, out_of_stock_products: int, filter: string, start_date: ?string, end_date: ?string, generated_at: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
