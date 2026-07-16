<?php

namespace App\Actions\Payment;

use App\Models\CustomerProfile;
use App\Models\Payment;

class GetCustomerPaymentAction
{
    public function handle(CustomerProfile $profile, int $paymentId): ?Payment
    {
        return $profile
            ->payments()
            ->with('payable')
            ->whereKey($paymentId)
            ->first();
    }
}
