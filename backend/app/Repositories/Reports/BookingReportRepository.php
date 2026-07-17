<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\Booking;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class BookingReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::Bookings;
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
                    'booking_number',
                    'customer_profile_id',
                    'service_id',
                    'status',
                    'requested_date',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (Booking $booking): array => [
            'id' => $booking->id,
            'booking_number' => $booking->booking_number,
            'customer_profile_id' => $booking->customer_profile_id,
            'service_id' => $booking->service_id,
            'status' => $booking->status->value,
            'requested_date' => $booking->requested_date?->toDateString(),
            'created_at' => $booking->created_at?->toISOString(),
        ]);
    }

    /**
     * @return Builder<Booking>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return Booking::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters->dateTo()))
            ->when($filters->status() !== null, fn (Builder $query): Builder => $query->whereIn('status', (array) $filters->status()))
            ->when($filters->customerId() !== null, fn (Builder $query): Builder => $query->where('customer_profile_id', $filters->customerId()));
    }
}
