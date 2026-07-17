<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\Product;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class InventoryReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::Inventory;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(NormalizedReportFilters $filters): array
    {
        return [
            'total_records' => $this->query($filters)->count(),
            'total_stock' => (int) $this->query($filters)->sum('current_stock'),
        ];
    }

    /**
     * Additive stock-level breakdown used by the inventory report service.
     * Buckets are mutually exclusive and always sum to the total product
     * count: out of stock (no stock), low stock (at or below the product's
     * low stock threshold), and in stock (above the threshold).
     *
     * @return array{in_stock: int, low_stock: int, out_of_stock: int}
     */
    public function stockLevelSummary(NormalizedReportFilters $filters): array
    {
        return [
            'in_stock' => $this->query($filters)
                ->whereColumn('current_stock', '>', 'low_stock_threshold')
                ->count(),
            'low_stock' => $this->query($filters)
                ->where('current_stock', '>', 0)
                ->whereColumn('current_stock', '<=', 'low_stock_threshold')
                ->count(),
            'out_of_stock' => $this->query($filters)
                ->where('current_stock', '<=', 0)
                ->count(),
        ];
    }

    public function rows(NormalizedReportFilters $filters, ReportCursorPagination $pagination): CursorPaginator
    {
        $paginator = $this->query($filters)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                perPage: $pagination->limit(),
                columns: [
                    'id',
                    'sku',
                    'name',
                    'status',
                    'selling_price',
                    'current_stock',
                    'low_stock_threshold',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (Product $product): array => [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'status' => $product->status->value,
            'selling_price' => (float) $product->selling_price,
            'current_stock' => $product->current_stock,
            'low_stock_threshold' => $product->low_stock_threshold,
            'created_at' => $product->created_at?->toISOString(),
        ]);
    }

    /**
     * @return Builder<Product>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return Product::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters->dateTo()))
            ->when($filters->status() !== null, fn (Builder $query): Builder => $query->whereIn('status', (array) $filters->status()));
    }
}
