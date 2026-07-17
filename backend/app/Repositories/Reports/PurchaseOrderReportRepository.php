<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\PurchaseOrder;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::PurchaseOrders;
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
                    'po_number',
                    'supplier_id',
                    'status',
                    'currency',
                    'subtotal',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (PurchaseOrder $purchaseOrder): array => [
            'id' => $purchaseOrder->id,
            'po_number' => $purchaseOrder->po_number,
            'supplier_id' => $purchaseOrder->supplier_id,
            'status' => $purchaseOrder->status->value,
            'currency' => $purchaseOrder->currency,
            'subtotal' => (float) $purchaseOrder->subtotal,
            'created_at' => $purchaseOrder->created_at?->toISOString(),
        ]);
    }

    /**
     * @return Builder<PurchaseOrder>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return PurchaseOrder::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters->dateTo()))
            ->when($filters->status() !== null, fn (Builder $query): Builder => $query->whereIn('status', (array) $filters->status()))
            ->when($filters->supplierId() !== null, fn (Builder $query): Builder => $query->where('supplier_id', $filters->supplierId()));
    }
}
