<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\CustomerProfile;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class CustomerReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::Customers;
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
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                perPage: $pagination->limit(),
                columns: [
                    'id',
                    'user_id',
                    'full_name',
                    'preferred_language',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (CustomerProfile $customerProfile): array => [
            'id' => $customerProfile->id,
            'user_id' => $customerProfile->user_id,
            'full_name' => $customerProfile->full_name,
            'preferred_language' => $customerProfile->preferred_language,
            'created_at' => $customerProfile->created_at?->toISOString(),
        ]);
    }

    /**
     * The generic status filter targets the customer classification column
     * ('lead' or 'active_customer'), mirroring how the booking and payment
     * repositories apply their status filters.
     *
     * @return Builder<CustomerProfile>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return CustomerProfile::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters->dateTo()))
            ->when($filters->status() !== null, fn (Builder $query): Builder => $query->whereIn('classification', (array) $filters->status()))
            ->when($filters->customerId() !== null, fn (Builder $query): Builder => $query->where('id', $filters->customerId()));
    }
}
