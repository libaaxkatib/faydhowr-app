<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\Quotation;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class QuotationReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::Quotations;
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
                    'quotation_number',
                    'customer_profile_id',
                    'status',
                    'currency',
                    'total_amount',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (Quotation $quotation): array => [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'customer_profile_id' => $quotation->customer_profile_id,
            'status' => $quotation->status->value,
            'currency' => $quotation->currency,
            'total_amount' => (float) $quotation->total_amount,
            'created_at' => $quotation->created_at?->toISOString(),
        ]);
    }

    /**
     * @return Builder<Quotation>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return Quotation::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters->dateTo()))
            ->when($filters->status() !== null, fn (Builder $query): Builder => $query->whereIn('status', (array) $filters->status()))
            ->when($filters->customerId() !== null, fn (Builder $query): Builder => $query->where('customer_profile_id', $filters->customerId()));
    }
}
