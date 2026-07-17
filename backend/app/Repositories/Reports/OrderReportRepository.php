<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\Order;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class OrderReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::Orders;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(NormalizedReportFilters $filters): array
    {
        return [
            'total_records' => $this->query($filters)->count(),
            'total_amount' => (float) $this->query($filters)->sum('total_amount'),
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
                    'order_number',
                    'customer_profile_id',
                    'status',
                    'currency',
                    'total_amount',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (Order $order): array => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer_profile_id' => $order->customer_profile_id,
            'status' => $order->status->value,
            'currency' => $order->currency,
            'total_amount' => (float) $order->total_amount,
            'created_at' => $order->created_at?->toISOString(),
        ]);
    }

    /**
     * @return Builder<Order>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return Order::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters->dateTo()))
            ->when($filters->status() !== null, fn (Builder $query): Builder => $query->whereIn('status', (array) $filters->status()))
            ->when($filters->customerId() !== null, fn (Builder $query): Builder => $query->where('customer_profile_id', $filters->customerId()));
    }
}
