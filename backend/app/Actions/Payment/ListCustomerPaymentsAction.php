<?php

namespace App\Actions\Payment;

use App\Enums\PaymentStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListCustomerPaymentsAction
{
    /**
     * @return LengthAwarePaginator<int, Payment>
     */
    public function handle(
        CustomerProfile $profile,
        ?PaymentStatus $status,
        ?string $gateway,
        ?string $payableType,
        int $perPage,
    ): LengthAwarePaginator {
        return $profile
            ->payments()
            ->with('payable')
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->when($gateway !== null, fn ($query) => $query->where('gateway', $gateway))
            ->when($payableType !== null, fn ($query) => $query->where('payable_type', $this->resolvePayableType($payableType)))
            ->latest()
            ->paginate($perPage);
    }

    private function resolvePayableType(string $payableType): string
    {
        return match ($payableType) {
            'order' => Order::class,
            default => $payableType,
        };
    }
}
