<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\GoodsReceipt;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class GoodsReceiptReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::GoodsReceipts;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(NormalizedReportFilters $filters): array
    {
        return [
            'total_records' => $this->query($filters)->count(),
        ];
    }

    public function rows(NormalizedReportFilters $filters, ReportCursorPagination $pagination): CursorPaginator
    {
        $paginator = $this->query($filters)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                perPage: $pagination->limit(),
                columns: [
                    'id',
                    'gr_number',
                    'supplier_id',
                    'purchase_order_id',
                    'received_at',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (GoodsReceipt $goodsReceipt): array => [
            'id' => $goodsReceipt->id,
            'gr_number' => $goodsReceipt->gr_number,
            'supplier_id' => $goodsReceipt->supplier_id,
            'purchase_order_id' => $goodsReceipt->purchase_order_id,
            'received_at' => $goodsReceipt->received_at?->toISOString(),
            'created_at' => $goodsReceipt->created_at?->toISOString(),
        ]);
    }

    /**
     * Date filters target received_at, the domain date of a goods receipt.
     *
     * @return Builder<GoodsReceipt>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return GoodsReceipt::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('received_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('received_at', '<=', $filters->dateTo()))
            ->when($filters->supplierId() !== null, fn (Builder $query): Builder => $query->where('supplier_id', $filters->supplierId()));
    }
}
