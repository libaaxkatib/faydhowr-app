<?php

namespace App\Enums;

enum ServicePaymentType: string
{
    case FullBeforeService = 'full_before_service';
    case Deposit = 'deposit';
    case PayAfterService = 'pay_after_service';
}
