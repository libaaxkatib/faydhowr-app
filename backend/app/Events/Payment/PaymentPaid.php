<?php

namespace App\Events\Payment;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public Payment $payment) {}
}
