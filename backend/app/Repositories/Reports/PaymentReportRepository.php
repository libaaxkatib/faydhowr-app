<?php

namespace App\Repositories\Reports;

use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\Payment;
use App\Support\Reports\TransformedCursorPaginator;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

class PaymentReportRepository implements ReportRepositoryInterface
{
    public function supports(ReportType $type): bool
    {
        return $type === ReportType::Payments;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(NormalizedReportFilters $filters): array
    {
        return [
            'total_records' => $this->query($filters)->count(),
            'total_amount' => (float) $this->query($filters)->sum('amount'),
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
                    'payment_number',
                    'customer_profile_id',
                    'status',
                    'amount',
                    'currency',
                    'gateway',
                    'paid_at',
                    'created_at',
                ],
                cursor: $pagination->cursor(),
            );

        return new TransformedCursorPaginator($paginator, fn (Payment $payment): array => [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'customer_profile_id' => $payment->customer_profile_id,
            'status' => $payment->status->value,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'gateway' => $payment->gateway,
            'paid_at' => $payment->paid_at?->toISOString(),
            'created_at' => $payment->created_at?->toISOString(),
        ]);
    }

    /**
     * The payment_status filter targets the payment status column;
     * the generic status filter applies as well for consistency.
     *
     * @return Builder<Payment>
     */
    private function query(NormalizedReportFilters $filters): Builder
    {
        return Payment::query()
            ->when($filters->dateFrom() !== null, fn (Builder $query): Builder => $query->where('created_at', '>=', $filters->dateFrom()))
            ->when($filters->dateTo() !== null, fn (Builder $query): Builder => $query->where('created_at', '<=', $filters->dateTo()))
            ->when($filters->status() !== null, fn (Builder $query): Builder => $query->whereIn('status', (array) $filters->status()))
            ->when($filters->paymentStatus() !== null, fn (Builder $query): Builder => $query->whereIn('status', (array) $filters->paymentStatus()))
            ->when($filters->customerId() !== null, fn (Builder $query): Builder => $query->where('customer_profile_id', $filters->customerId()));
    }
}
