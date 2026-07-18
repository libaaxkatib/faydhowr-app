<?php

namespace App\Enums;

enum PaymentStage: string
{
    case Deposit = 'deposit';
    case Balance = 'balance';
    case Full = 'full';
}
