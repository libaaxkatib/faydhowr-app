<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\StoreOrder;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class StoreOrderReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::StoreOrders;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(NormalizedReportFilters $filters): array
    {
        return [
            'total_records' => $this->query($filters)->count(),
            'total_amount' => (float) $this->query($filters)->sum('subtotal'),
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
                    'store_order_number',
                    'customer_profile_id',
                    'status',
                    'currency',
                    'total_items',
                    'total_quantity',
                    'subtotal',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (StoreOrder $storeOrder): array => [
            'id' => $storeOrder->id,
            'store_order_number' => $storeOrder->store_order_number,
            'customer_profile_id' => $storeOrder->customer_profile_id,
            'status' => $storeOrder->status->value,
            'currency' => $storeOrder->currency,
            'total_items' => $storeOrder->total_items,
            'total_quantity' => $storeOrder->total_quantity,
            'subtotal' => (float) $storeOrder->subtotal,
            'created_at' => $storeOrder->created_at?->toISOString(),
        ]);
    }

    /**
     * @return Builder<StoreOrder>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return StoreOrder::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters->dateTo()))
            ->when($filters->status() !== null, fn (Builder $query): Builder => $query->whereIn('status', (array) $filters->status()))
            ->when($filters->customerId() !== null, fn (Builder $query): Builder => $query->where('customer_profile_id', $filters->customerId()));
    }
}
