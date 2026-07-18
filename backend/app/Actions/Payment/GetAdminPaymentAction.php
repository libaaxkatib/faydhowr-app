<?php

namespace App\Actions\Payment;

use App\Models\Payment;

class GetAdminPaymentAction
{
    public function handle(int $paymentId): ?Payment
    {
        return Payment::query()
            ->with(['customerProfile', 'payable', 'transactions', 'statusHistories'])
            ->whereKey($paymentId)
            ->first();
    }
}
