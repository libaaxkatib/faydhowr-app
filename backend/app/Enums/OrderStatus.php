<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
